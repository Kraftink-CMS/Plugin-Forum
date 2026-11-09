<?php

namespace KraftInk\Plugins\Forum\Services;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Extensions\Contracts\PrivacyHandler;
use KraftInk\Plugins\Forum\Models\Draft;
use KraftInk\Plugins\Forum\Models\Polls\Poll;
use KraftInk\Plugins\Forum\Models\Polls\PollBallot;
use KraftInk\Plugins\Forum\Models\Polls\PollOption;
use KraftInk\Plugins\Forum\Models\Polls\PollRevision;
use KraftInk\Plugins\Forum\Models\Posts\Announcement;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Models\Posts\Mention;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\PostLike;
use KraftInk\Plugins\Forum\Models\Posts\PostRevision;
use KraftInk\Plugins\Forum\Models\Posts\Report;
use KraftInk\Plugins\Forum\Models\Profile;
use KraftInk\Plugins\Forum\Models\Topics\Subscription;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use KraftInk\Plugins\Forum\Services\Notifications\ForumNotifications;
use RuntimeException;

class Privacy implements PrivacyHandler
{
    public function export(ExportingPersonalData $event): void
    {
        $user = $event->user;
        $topics = new ForumAccess()->topics($user)->select('forum__topics.id');
        $posts = Post::query()->whereIn('topic_id', clone $topics);
        if (!$user->hasPermission('forum.manage')) {
            $posts->where('hidden', false);
        }
        $event->data['forum'] = [
            'topics' => Topic::query()
                ->where('user_id', $user->id)
                ->whereIn('id', clone $topics)
                ->orderBy('id')
                ->select(['id', 'category_id', 'title', 'created_at', 'updated_at'])
                ->cursor(),
            'posts' => (clone $posts)
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->select(['id', 'topic_id', 'body', 'hidden', 'created_at', 'updated_at'])
                ->cursor(),
            'revisions' => PostRevision::query()
                ->where('user_id', $user->id)
                ->whereIn('post_id', (clone $posts)->select('id'))
                ->orderBy('id')
                ->select(['id', 'post_id', 'body', 'created_at'])
                ->cursor(),
            'reports' => Report::query()
                ->where('user_id', $user->id)
                ->whereIn('post_id', (clone $posts)->select('id'))
                ->orderBy('id')
                ->select(['post_id', 'reason', 'resolved', 'created_at'])
                ->cursor(),
            'likes' => PostLike::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->select(['post_id', 'created_at'])
                ->cursor(),
            'votes' => PollBallot::query()
                ->join('forum__polls', 'forum__polls.id', '=', 'forum__poll_ballots.poll_id')
                ->join('forum__poll_votes', 'forum__poll_votes.ballot_id', '=', 'forum__poll_ballots.id')
                ->join('forum__poll_options', 'forum__poll_options.id', '=', 'forum__poll_votes.option_id')
                ->where('forum__poll_ballots.user_id', $user->id)
                ->whereIn('forum__polls.topic_id', clone $topics)
                ->orderBy('forum__poll_votes.id')
                ->select(['forum__poll_ballots.poll_id', 'forum__poll_options.label', 'forum__poll_ballots.created_at'])
                ->cursor(),
            'profile' => Profile::query()
                ->where('user_id', $user->id)
                ->select(['signature', 'bio', 'website', 'discord', 'created_at', 'updated_at'])
                ->first(),
            'subscriptions' => Subscription::query()
                ->where('user_id', $user->id)
                ->whereIn('topic_id', clone $topics)
                ->orderBy('id')
                ->select(['topic_id', 'created_at'])
                ->cursor(),
            'mentions' => Mention::query()
                ->where('user_id', $user->id)
                ->whereIn('post_id', (clone $posts)->select('id'))
                ->orderBy('id')
                ->select(['post_id', 'created_at'])
                ->cursor(),
            'attachments' => Attachment::query()
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereNull('post_id')->orWhereIn('post_id', (clone $posts)->select('id')))
                ->orderBy('id')
                ->select(['id', 'name', 'mime', 'size', 'created_at'])
                ->cursor(),
            'drafts' => Draft::query()
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->orderBy('id')
                ->cursor()
                ->map(
                    fn ($draft) => [
                        'context' => $draft->context,
                        'draft' => $draft->payload,
                        'expires_at' => $draft->expires_at,
                    ],
                ),
        ];
    }

    public function anonymize(AccountDeleting $event): void
    {
        $id = $event->user->id;
        $posts = Post::query()->where('user_id', $id)->select('id');
        $topics = Topic::query()->where('user_id', $id)->select('id');
        if (
            Announcement::query()
                ->whereIn('post_id', clone $posts)
                ->where('status', 'sending')
                ->where('started_at', '>=', now()->subMinutes(10))
                ->exists()
        ) {
            throw new RuntimeException(__('forum::admin.automation.privacy_busy'));
        }
        Announcement::query()->whereIn('post_id', clone $posts)->delete();
        Draft::query()->where('user_id', $id)->delete();
        PostRevision::query()
            ->where(fn ($query) => $query->where('user_id', $id)->orWhereIn('post_id', clone $posts))
            ->update(['user_id' => null, 'body' => '', 'anonymized' => true]);
        Profile::query()->where('user_id', $id)->delete();
        app(ForumNotifications::class)->purgeUser($id);
        app(ForumFiles::class)->deleteWhere(fn ($query) => $query->where('user_id', $id), (clone $posts)->pluck('id'));
        Mention::query()->where('user_id', $id)->delete();
        Mention::query()
            ->where('actor_id', $id)
            ->update(['actor_id' => null, 'state' => 'cancelled', 'updated_at' => now()]);
        Report::query()->where('user_id', $id)->delete();
        Report::query()
            ->whereIn('post_id', clone $posts)
            ->update(['reason' => __('forum::messages.privacy.removed_report'), 'resolved' => true]);
        PostLike::query()->where('user_id', $id)->delete();
        PollBallot::query()->where('user_id', $id)->delete();
        $polls = Poll::query()->whereIn('topic_id', clone $topics)->select('id');
        PollRevision::query()
            ->where(fn ($query) => $query->where('user_id', $id)->orWhereIn('poll_id', clone $polls))
            ->update(['user_id' => null, 'snapshot' => json_encode(['anonymized' => true])]);
        PollOption::query()
            ->whereIn('poll_id', clone $polls)
            ->update(['label' => __('forum::messages.privacy.removed_option')]);
        Poll::query()
            ->whereIn('topic_id', clone $topics)
            ->update(['question' => __('forum::messages.privacy.removed_poll'), 'closed' => true]);
        Post::query()
            ->where('user_id', $id)
            ->update([
                'user_id' => null,
                'body' => '<p>'.e(__('forum::messages.privacy.removed_post')).'</p>',
                'updated_at' => now(),
            ]);
        Topic::query()
            ->where('user_id', $id)
            ->update([
                'user_id' => null,
                'title' => __('forum::messages.privacy.removed_topic'),
                'updated_at' => now(),
            ]);
    }
}
