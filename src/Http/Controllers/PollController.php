<?php

namespace KraftInk\Plugins\Forum\Http\Controllers;

use App\Kraft\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Polls\Poll;
use KraftInk\Plugins\Forum\Models\Polls\PollBallot;
use KraftInk\Plugins\Forum\Models\Polls\PollOption;
use KraftInk\Plugins\Forum\Models\Polls\PollRevision;
use KraftInk\Plugins\Forum\Models\Polls\PollVote;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use Illuminate\Http\RedirectResponse;
use App\Kraft\SiteDates;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PollController
{
    public function __construct(private ForumAccess $access)
    {
    }

    private function topic(Request $request, int $id): object
    {
        $topic = $this->access->topics($request->user())->where('forum__topics.id', $id)->first();
        if ($topic === null) {
            abort(404);
        }

        return $topic;
    }

    private function manage(Request $request, object $topic): void
    {
        $category = $topic->category;
        if (!$this->access->canManageTopic($request->user(), $category, $topic)) {
            throw new HttpException(403);
        }
    }

    public function save(Request $request, int $id): RedirectResponse
    {
        app(SiteDates::class)->normalize($request, ['closes_at']);
        $topic = $this->topic($request, $id);
        $this->manage($request, $topic);
        $data = $request->validate([
            'question' => 'required|string|max:200',
            'options' => 'required|string|max:2000',
            'max_choices' => 'required|integer|between:1,10',
            'closes_at' => 'nullable|date',
            'allow_change_votes' => 'sometimes|boolean',
            'allow_withdraw_votes' => 'sometimes|boolean',
            'results_visibility' => ['sometimes', Rule::in(['always', 'after_vote', 'closed'])],
            'reset_votes' => 'sometimes|boolean',
        ]);
        $options = array_values(
            array_unique(
                array_filter(
                    array_map('trim', preg_split('/\r\n|\r|\n/', $data['options'])),
                    fn ($option) => $option !== '',
                ),
            ),
        );
        if (
            count($options) < 2 ||
            count($options) > 10 ||
            (int) $data['max_choices'] > count($options) ||
            collect($options)->contains(fn ($option) => mb_strlen($option) > 160)
        ) {
            throw ValidationException::withMessages(['options' => __('forum::messages.polls.invalid_options')]);
        }
        DB::transaction(function () use ($request, $topic, $data, $options) {
            Topic::query()->where('id', $topic->id)->lockForUpdate()->first();
            $poll = $topic->poll()->lockForUpdate()->first();
            $attributes = [
                'question' => $data['question'],
                'max_choices' => (int) $data['max_choices'],
                'closes_at' => $data['closes_at'] ?? null,
                'allow_change_votes' => $request->boolean('allow_change_votes'),
                'allow_withdraw_votes' => $request->boolean('allow_withdraw_votes'),
                'results_visibility' => $data['results_visibility'] ?? 'always',
                'updated_at' => now(),
            ];
            $changed = true;
            if ($poll) {
                $previous = $poll->options()->orderBy('position')->pluck('label')->all();
                $changed =
                    $previous !== $options ||
                    $poll->question !== $data['question'] ||
                    (int) $poll->max_choices !== (int) $data['max_choices'];
                $ballots = $poll->ballots()->count();
                if ($changed && $ballots && !$request->boolean('reset_votes')) {
                    throw ValidationException::withMessages([
                        'reset_votes' => __('forum::messages.polls.poll_reset_required'),
                    ]);
                }
                PollRevision::query()->insert([
                    'poll_id' => $poll->id,
                    'user_id' => $request->user()->id,
                    'snapshot' => json_encode(
                        [
                            'question' => $poll->question,
                            'options' => $previous,
                            'max_choices' => $poll->max_choices,
                            'ballot_count' => $ballots,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => now(),
                ]);
                if ($changed) {
                    PollBallot::query()->where('poll_id', $poll->id)->delete();
                    $poll->options()->delete();
                }
                Poll::query()->where('id', $poll->id)->update($attributes);
                $pollId = $poll->id;
            } else {
                $pollId = Poll::query()->insertGetId(
                    $attributes + ['topic_id' => $topic->id, 'closed' => false, 'created_at' => now()],
                );
            }
            if ($changed) {
                foreach ($options as $position => $option) {
                    PollOption::query()->insert([
                        'poll_id' => $pollId,
                        'label' => $option,
                        'position' => $position,
                    ]);
                }
            }
            Audit::record('forum.poll_saved', ['topic_id' => $topic->id, 'reset_votes' => $poll && $changed]);
        });

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function vote(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'options' => 'required|array|min:1|max:10',
            'options.*' => 'required|integer|distinct',
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $topic = $this->topic($request, $id);
            $this->writable($request, $topic);
            $poll = Poll::query()->where('topic_id', $id)->lockForUpdate()->first();
            $this->open($poll);
            if (
                !(
                    count($data['options']) <= $poll->max_choices &&
                    $poll->options()->whereIn('id', $data['options'])->count() === count($data['options'])
                )
            ) {
                throw new HttpException(422, __('forum::messages.polls.invalid_options'));
            }
            $ballot = PollBallot::query()
                ->where('poll_id', $poll->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            if ($ballot && !$poll->allow_change_votes) {
                throw new HttpException(409, __('forum::messages.polls.already_voted'));
            }
            if ($ballot) {
                PollVote::query()->where('ballot_id', $ballot->id)->delete();
                $ballotId = $ballot->id;
            } else {
                $ballotId = PollBallot::query()->insertGetId([
                    'poll_id' => $poll->id,
                    'user_id' => $request->user()->id,
                    'created_at' => now(),
                ]);
            }
            foreach ($data['options'] as $option) {
                PollVote::query()->insert(['ballot_id' => $ballotId, 'option_id' => $option]);
            }
        });

        return back();
    }

    public function withdraw(Request $request, int $id): RedirectResponse
    {
        DB::transaction(function () use ($request, $id) {
            $topic = $this->topic($request, $id);
            $this->writable($request, $topic);
            $poll = Poll::query()->where('topic_id', $id)->lockForUpdate()->first();
            $this->open($poll);
            if (!$poll->allow_withdraw_votes) {
                throw new HttpException(403);
            }
            PollBallot::query()->where('poll_id', $poll->id)->where('user_id', $request->user()->id)->delete();
        });

        return back();
    }

    private function writable(Request $request, object $topic): void
    {
        $category = Category::query()->find($topic->category_id);
        if (!$this->access->canWrite($request->user(), $category)) {
            throw new HttpException(403);
        }
        if ($topic->locked && !$this->access->permission($request->user(), 'forum.bypass-lock')) {
            throw new HttpException(422, __('forum::messages.discussions.locked'));
        }
    }

    private function open(?object $poll): void
    {
        if (!($poll && !$poll->closed && (!$poll->closes_at || now()->lt($poll->closes_at)))) {
            throw new HttpException(422, __('forum::messages.polls.poll_closed'));
        }
    }

    public function close(Request $request, int $id): RedirectResponse
    {
        $request->validate(['closed' => 'required|boolean']);
        $topic = $this->topic($request, $id);
        $this->manage($request, $topic);
        Poll::query()
            ->where('topic_id', $id)
            ->update(['closed' => $request->boolean('closed'), 'updated_at' => now()]);

        return back();
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $topic = $this->topic($request, $id);
        $this->manage($request, $topic);
        $request->validate(['confirmed' => 'accepted']);
        Poll::query()->where('topic_id', $id)->delete();
        Audit::record('forum.poll_deleted', ['topic_id' => $id]);

        return back();
    }
}
