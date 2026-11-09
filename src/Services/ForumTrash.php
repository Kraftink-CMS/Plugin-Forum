<?php

namespace KraftInk\Plugins\Forum\Services;

use App\Kraft\Audit;
use App\Kraft\Notifications\Notifications;
use App\Kraft\Trash\TrashRegistry;
use App\Kraft\Trash\TrashSource;
use App\Models\Accounts\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Forum\Models\Draft;
use KraftInk\Plugins\Forum\Models\Posts\Announcement;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\Report;
use KraftInk\Plugins\Forum\Models\Topics\Subscription;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ForumTrash
{
    public function restoreTopic(User $actor, int $id): void
    {
        if (!app(ForumAccess::class)->canModerate($actor)) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            $topic = app(ForumAccess::class)
                ->topics($actor, withDeleted: true)
                ->where('forum__topics.id', $id)
                ->whereNotNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (!$topic) {
                abort(404);
            }
            if (!($topic->user_id && User::query()->whereKey($topic->user_id)->whereNull('anonymized_at')->exists())) {
                throw new HttpException(410);
            }
            Topic::query()
                ->where('id', $id)
                ->update(['deleted_at' => null, 'updated_at' => now()]);
            Audit::record('forum.topic_restored', ['id' => $id], $actor);
        }, 3);
    }

    public function restorePost(User $actor, int $id): void
    {
        if (!app(ForumAccess::class)->canModerate($actor)) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            $post = Post::query()->where('id', $id)->first();
            if ($post === null) {
                abort(404);
            }
            $topic = app(ForumAccess::class)
                ->topics($actor)
                ->where('forum__topics.id', $post->topic_id)
                ->lockForUpdate()
                ->first();
            if (!$topic) {
                abort(404);
            }
            $post = Post::query()->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->first();
            if (!($post && $post->topic_id === $topic->id)) {
                abort(404);
            }
            if (
                !(
                    $post->user_id &&
                    trim($post->body) !== '' &&
                    User::query()->whereKey($post->user_id)->whereNull('anonymized_at')->exists()
                )
            ) {
                throw new HttpException(410);
            }
            Post::query()
                ->where('id', $id)
                ->update(['deleted_at' => null, 'updated_at' => now()]);
            Report::query()
                ->where('post_id', $id)
                ->update(['resolved' => true]);
            Audit::record('forum.post_restored', ['post_id' => $id], $actor);
        }, 3);
    }

    public function register(TrashRegistry $registry): void
    {
        $registry->register(
            new TrashSource(
                'forum.topics',
                'forum::messages.trash.topics',
                [],
                [],
                fn (User $user) => app(ForumAccess::class)
                    ->topics($user, withDeleted: true)
                    ->leftJoin('users as author', 'author.id', '=', 'forum__topics.user_id')
                    ->whereNotNull('forum__topics.deleted_at')
                    ->select(['forum__topics.id as id', 'forum__topics.deleted_at as deleted_at'])
                    ->selectRaw(
                        'CASE WHEN forum__topics.user_id IS NULL OR author.anonymized_at IS NOT NULL THEN ? ELSE forum__topics.title END as title',
                        [__('forum::messages.privacy.removed_topic')],
                    )
                    ->selectRaw(
                        'CASE WHEN forum__topics.user_id IS NULL OR author.anonymized_at IS NOT NULL THEN ? ELSE NULL END as restore_reason',
                        ['trash.restore_anonymized'],
                    ),
                fn (User $actor, int $id) => app(ForumTrash::class)->restoreTopic($actor, $id),
                purgePermissions: ['forum.purge'],
                purge: fn (User $actor, int $id) => app(ForumTrash::class)->purgeTopic($actor, $id),
                authorize: fn (User $actor) => app(ForumAccess::class)->canModerate($actor),
            ),
        );
        $registry->register(
            new TrashSource(
                'forum.posts',
                'forum::messages.trash.posts',
                [],
                [],
                fn (User $user) => Post::query()
                    ->join('forum__topics', 'forum__topics.id', '=', 'forum__posts.topic_id')
                    ->leftJoin('users as author', 'author.id', '=', 'forum__posts.user_id')
                    ->leftJoin('users as topic_author', 'topic_author.id', '=', 'forum__topics.user_id')
                    ->whereIn('forum__topics.id', app(ForumAccess::class)->topics($user)->select('forum__topics.id'))
                    ->whereNotNull('forum__posts.deleted_at')
                    ->select(['forum__posts.id as id', 'forum__posts.deleted_at as deleted_at'])
                    ->selectRaw(
                        'CASE WHEN forum__topics.user_id IS NULL OR topic_author.anonymized_at IS NOT NULL THEN ? ELSE forum__topics.title END as title',
                        [__('forum::messages.privacy.removed_topic')],
                    )
                    ->selectRaw(
                        "CASE WHEN forum__posts.user_id IS NULL OR author.anonymized_at IS NOT NULL OR TRIM(forum__posts.body) = '' THEN ? ELSE NULL END as restore_reason",
                        ['trash.restore_anonymized'],
                    ),
                fn (User $actor, int $id) => app(ForumTrash::class)->restorePost($actor, $id),
                purgePermissions: ['forum.purge'],
                purge: fn (User $actor, int $id) => app(ForumTrash::class)->purgePost($actor, $id),
                authorize: fn (User $actor) => app(ForumAccess::class)->canModerate($actor),
            ),
        );
    }

    public function purgeTopic(User $actor, int $id): void
    {
        if (!(app(ForumAccess::class)->canModerate($actor) && $actor->hasPermission('forum.purge'))) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            $topic = app(ForumAccess::class)
                ->topics($actor, withDeleted: true)
                ->where('forum__topics.id', $id)
                ->whereNotNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (!$topic) {
                abort(404);
            }
            $posts = Post::query()->where('topic_id', $id)->orderBy('id')->lockForUpdate()->pluck('id');
            $this->removePostResources($posts);
            Draft::query()
                ->where('context', 'topic_'.$id)
                ->delete();
            Topic::query()->where('id', $id)->delete();
            Audit::record('forum.topic_purged', ['id' => $id], $actor);
        }, 3);
    }

    public function purgePost(User $actor, int $id): void
    {
        if (!(app(ForumAccess::class)->canModerate($actor) && $actor->hasPermission('forum.purge'))) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            $post = Post::query()->where('id', $id)->first();
            if ($post === null) {
                abort(404);
            }
            $topic = app(ForumAccess::class)
                ->topics($actor)
                ->where('forum__topics.id', $post->topic_id)
                ->lockForUpdate()
                ->first();
            if (!$topic) {
                abort(404);
            }
            $post = Post::query()->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->first();
            if (!($post && $post->topic_id === $topic->id)) {
                abort(404);
            }
            $this->removePostResources(collect([$id]));
            Subscription::query()
                ->where('pending_post_id', $id)
                ->update(['pending_post_id' => null]);
            Post::query()->where('id', $id)->delete();
            Audit::record('forum.post_purged', ['id' => $id, 'topic_id' => $topic->id], $actor);
        }, 3);
    }

    private function removePostResources(Collection $posts): void
    {
        $attachments = Attachment::query()
            ->whereIn('post_id', $posts)
            ->lockForUpdate()
            ->get(['id', 'path']);
        app(ForumFiles::class)->assertDeletable($attachments, $posts);
        $announcements = Announcement::query()->whereIn('post_id', $posts)->lockForUpdate()->get();
        if (
            $announcements->contains(
                fn (Announcement $row) => $row->status === 'sending' &&
                    $row->started_at >= now()->subMinutes(10)->toDateTimeString(),
            )
        ) {
            throw new HttpException(409, __('notifications.busy'));
        }
        foreach ($posts as $post) {
            app(Notifications::class)->forget('forum', 'post:'.$post);
        }
        foreach ($attachments->pluck('path') as $path) {
            app(ForumFiles::class)->scheduleDeletion($path);
        }
    }
}
