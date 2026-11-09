<?php

namespace KraftInk\Plugins\Forum\Services\Notifications;

use App\Exceptions\OperationBusy;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Html;
use App\Kraft\Notifications\Notifications;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Forum\Models\Posts\Mention;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use Throwable;
use KraftInk\Plugins\Forum\Services\ForumAccess;

class ForumMentions
{
    public function enqueue(int $post, int $actor, string $body): void
    {
        preg_match_all(
            '/(?<![A-Za-z0-9_.-])@([A-Za-z0-9][A-Za-z0-9_.-]{1,39})(?![A-Za-z0-9_.-])/u',
            html_entity_decode(strip_tags(Html::clean($body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches,
        );
        $names = array_slice(array_values(array_unique(array_map('strtolower', $matches[1]))), 0, 20);
        $users = User::members()
            ->whereIn(DB::raw(AccountIdentity::expression()), $names)
            ->whereKeyNot($actor)
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->pluck('id');
        $removed = Mention::query()
            ->where('post_id', $post)
            ->whereNotIn('user_id', $users)
            ->where('state', '!=', 'cancelled');
        foreach ((clone $removed)->whereNotNull('notice_id')->get(['post_id', 'user_id']) as $mention) {
            /** @var Mention $mention */
            app(Notifications::class)->dismiss('forum', 'post:'.$mention->post_id, $mention->user_id);
        }
        $removed->update(['state' => 'cancelled', 'updated_at' => now()]);
        foreach ($users as $user) {
            Mention::query()->insertOrIgnore([
                'post_id' => $post,
                'user_id' => $user,
                'actor_id' => $actor,
                'state' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            DB::afterCommit(function () use ($post): void {
                if (app()->isDownForMaintenance()) {
                    return;
                }
                try {
                    app(OperationLock::class)->shared(fn () => $this->process(post: $post));
                } catch (OperationBusy) {
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    public function process(int $limit = 100, ?int $post = null): int
    {
        $processed = 0;
        $started = microtime(true);
        $ids = Mention::query()
            ->where('state', 'pending')
            ->when($post !== null, fn ($query) => $query->where('post_id', $post))
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) - $started > 40) {
                break;
            }
            DB::transaction(function () use ($id, &$processed) {
                $mention = Mention::query()->where('id', $id)->lockForUpdate()->first();
                if (!$mention || $mention->state !== 'pending') {
                    return;
                }
                $user = User::query()->find($mention->user_id);
                $post = Post::query()
                    ->published()
                    ->where('id', $mention->post_id)
                    ->first();
                $topic =
                    $user && !$user->anonymized_at && !$user->import_locked && !$user->isBanned() && $post
                        ? new ForumAccess()->topics($user)->where('forum__topics.id', $post->topic_id)->first()
                        : null;
                if (!$topic) {
                    Mention::query()
                        ->where('id', $id)
                        ->update(['state' => 'cancelled', 'updated_at' => now()]);

                    return;
                }
                $noticeId = app(Notifications::class)
                    ->deliveries('forum')
                    ->where('user_id', $user->id)
                    ->where('type', 'forum.post')
                    ->where('resource', 'post:'.$post->id)
                    ->value('notice_id');
                if (!$noticeId) {
                    $noticeId = app(Notifications::class)->notify(
                        'forum.post',
                        $user,
                        ['topic_id' => $topic->id, 'post_id' => $post->id, 'mention' => true],
                        'post:'.$post->id,
                        resource: 'post:'.$post->id,
                    );
                    $processed += (int) ($noticeId !== null);
                }
                Mention::query()
                    ->where('id', $id)
                    ->update(['state' => 'delivered', 'notice_id' => $noticeId, 'updated_at' => now()]);
            }, 3);
        }

        return $processed;
    }
}
