<?php

namespace KraftInk\Plugins\Forum\Services\Notifications;

use App\Exceptions\OperationBusy;
use App\Kraft\Notifications\NotificationRegistry;
use App\Kraft\Notifications\Notifications;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Forum\Models\Posts\Mention;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Topics\Subscription;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\TopicUrl;
use Throwable;

class ForumNotifications
{
    public function follow(int $topic, int $user, bool $enabled, int $read = 0): void
    {
        if (!$enabled) {
            Subscription::query()->where('topic_id', $topic)->where('user_id', $user)->delete();

            return;
        }
        Subscription::query()->insertOrIgnore([
            'topic_id' => $topic,
            'user_id' => $user,
            'last_read_post_id' => $read,
            'last_notified_post_id' => $read,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function enqueue(int $topic, int $post, int $author): void
    {
        Subscription::query()
            ->where('topic_id', $topic)
            ->where('user_id', '!=', $author)
            ->whereColumn('last_notified_post_id', '<=', 'last_read_post_id')
            ->update(['pending_post_id' => $post, 'updated_at' => now()]);
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            DB::afterCommit(function () use ($post): void {
                if (app()->isDownForMaintenance()) {
                    return;
                }
                try {
                    app(OperationLock::class)->shared(function () use ($post): void {
                        app(ForumMentions::class)->process(post: $post);
                        $this->process(post: $post);
                    });
                } catch (OperationBusy) {
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    public function read(int $topic, int $user, int $post): void
    {
        Subscription::query()
            ->where('topic_id', $topic)
            ->where('user_id', $user)
            ->where('last_read_post_id', '<', $post)
            ->update(['last_read_post_id' => $post, 'updated_at' => now()]);
    }

    public function process(int $limit = 100, ?int $post = null): int
    {
        $processed = 0;
        $started = microtime(true);
        $ids = Subscription::query()
            ->whereNotNull('pending_post_id')
            ->when($post !== null, fn ($query) => $query->where('pending_post_id', $post))
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) - $started > 40) {
                break;
            }
            DB::transaction(function () use ($id, &$processed) {
                $subscription = Subscription::query()->where('id', $id)->lockForUpdate()->first();
                if (!$subscription || !$subscription->pending_post_id) {
                    return;
                }
                $user = User::query()->find($subscription->user_id);
                $access = new ForumAccess();
                $topic =
                    $user && !$user->anonymized_at && !$user->import_locked && !$user->isBanned()
                        ? $access->topics($user)->where('forum__topics.id', $subscription->topic_id)->first()
                        : null;
                $post = $topic
                    ? $topic->posts()->published()->find($subscription->pending_post_id)
                    : null;
                if (
                    $post &&
                    $subscription->last_read_post_id < $post->id &&
                    $subscription->last_notified_post_id <= $subscription->last_read_post_id
                ) {
                    $service = app(Notifications::class);
                    $alreadySent = $service
                        ->deliveries('forum')
                        ->where('user_id', $user->id)
                        ->where('type', 'forum.post')
                        ->where('resource', 'post:'.$post->id)
                        ->exists();
                    $notice = $service->notify(
                        'forum.post',
                        $user,
                        ['topic_id' => $topic->id, 'post_id' => $post->id],
                        'post:'.$post->id,
                        resource: 'post:'.$post->id,
                    );
                    Subscription::query()
                        ->where('id', $id)
                        ->update(['last_notified_post_id' => $post->id]);
                    $processed += (int) (!$alreadySent && $notice !== null);
                }
                Subscription::query()
                    ->where('id', $id)
                    ->update(['pending_post_id' => null, 'updated_at' => now()]);
            }, 3);
        }

        return $processed;
    }

    public function purgeUser(int $user): void
    {
        app(Notifications::class)->forget('forum', user: $user);
        Subscription::query()->where('user_id', $user)->delete();
    }

    public static function register(NotificationRegistry $registry): void
    {
        $registry->register(
            'forum',
            'forum.post',
            'forum::messages.notifications.notice_title',
            fn (array $data): array => [
                'title' => __(
                    'forum::messages.notifications.'.($data['mention'] ?? false ? 'mention_title' : 'notice_title'),
                ),
                'body' => '<p>'.
                    e(
                        __(
                            'forum::messages.notifications.'.
                                ($data['mention'] ?? false ? 'mention_body' : 'notice_body'),
                        ),
                    ).
                    '</p>',
                'url' => TopicUrl::forPost($data['topic_id'], $data['post_id']),
            ],
            function (?User $user, array $data): bool {
                if (
                    !$user ||
                    !app(ForumAccess::class)->topics($user)->where('forum__topics.id', $data['topic_id'])->exists() ||
                    !Post::query()
                        ->published()
                        ->where('id', $data['post_id'])
                        ->where('topic_id', $data['topic_id'])
                        ->exists()
                ) {
                    return false;
                }
                if ($data['mention'] ?? false) {
                    return Mention::query()
                        ->where('post_id', $data['post_id'])
                        ->where('user_id', $user->id)
                        ->where('state', '!=', 'cancelled')
                        ->exists();
                }

                return Subscription::query()
                    ->where('topic_id', $data['topic_id'])
                    ->where('user_id', $user->id)
                    ->where('last_read_post_id', '<', $data['post_id'])
                    ->exists();
            },
        );
    }
}
