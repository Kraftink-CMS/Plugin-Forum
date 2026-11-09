<?php

namespace KraftInk\Plugins\Forum\Services\Notifications;

use App\Exceptions\OperationBusy;
use App\Kraft\LocalePolicy;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\RetentionPolicy;
use App\Kraft\System\WebhookClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Posts\Announcement;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\TopicUrl;
use Throwable;
use App\Kraft\System\OperationLock;

class ForumAnnouncements
{
    public function enqueue(int $post, string $event): void
    {
        $settings = app(Settings::class);
        $configuration = $settings->get('forum.discord.configuration_id');
        if (
            !$settings->get('forum.discord.enabled', false) ||
            !is_string($configuration) ||
            !Str::isUuid($configuration) ||
            !in_array($event, $settings->get('forum.discord.events', ['topic']), true) ||
            !$this->eligible($post)
        ) {
            return;
        }
        $id = (string) Str::uuid();
        $inserted = Announcement::query()->insertOrIgnore([
            'id' => $id,
            'post_id' => $post,
            'event' => $event,
            'configuration_id' => $configuration,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted && config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            DB::afterCommit(function () use ($id): void {
                if (app()->isDownForMaintenance()) {
                    return;
                }
                try {
                    $this->deliver($id);
                } catch (OperationBusy) {
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    public function eligible(int $post): ?object
    {
        $entry = Post::query()
            ->published()
            ->where('id', $post)
            ->whereNotNull('user_id')
            ->first();
        if (!$entry) {
            return null;
        }
        $topic = new ForumAccess()->topics(null)->where('forum__topics.id', $entry->topic_id)->first();
        if (!$topic) {
            return null;
        }
        $locale = Category::query()->where('id', $topic->category_id)->value('locale');
        $policy = app(LocalePolicy::class);

        return $policy->valid($locale) && ($policy->allowsChoice() || $locale === $policy->defaultLocale())
            ? $entry
            : null;
    }

    public function run(int $limit = 50): int
    {
        return app(OperationLock::class)->shared(fn () => $this->runLocked($limit));
    }

    private function runLocked(int $limit): int
    {
        if (app()->isDownForMaintenance() || DB::transactionLevel() > 0) {
            return 0;
        }
        Announcement::query()
            ->where('status', 'sending')
            ->where('started_at', '<', now()->subMinutes(10))
            ->update(['status' => 'uncertain', 'updated_at' => now()]);
        $ids = Announcement::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->limit(max(1, min(100, $limit)))
            ->pluck('id');
        $processed = 0;
        $started = microtime(true);
        foreach ($ids as $id) {
            if (microtime(true) - $started > 40) {
                break;
            }
            $processed += (int) $this->deliver($id);
        }

        return $processed;
    }

    public function cleanup(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(app(RetentionPolicy::class)->days('technical'));
        $expired = Announcement::query()
            ->whereIn('status', ['delivered', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->where(
                fn ($query) => $query
                    ->whereNotNull('message_id')
                    ->orWhereNotNull('http_status')
                    ->orWhere('attempts', '>', 0),
            );
        if ($dryRun) {
            return $expired->count();
        }
        $count = 0;
        $expired->orderBy('id')->chunkById(500, function ($rows) use ($cutoff, &$count): void {
            $count += Announcement::query()
                ->whereIn('id', $rows->pluck('id'))
                ->whereIn('status', ['delivered', 'cancelled'])
                ->where('updated_at', '<', $cutoff)
                ->update(['message_id' => null, 'http_status' => null, 'attempts' => 0]);
        });

        return $count;
    }

    public function deliver(string $id): bool
    {
        return app(OperationLock::class)->shared(fn () => $this->deliverLocked($id));
    }

    private function deliverLocked(string $id): bool
    {
        $row = DB::transaction(function () use ($id) {
            $row = Announcement::query()->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending' || now()->lt($row->available_at)) {
                return null;
            }
            Announcement::query()
                ->where('id', $id)
                ->update([
                    'status' => 'sending',
                    'attempts' => $row->attempts + 1,
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);
            $row->attempts++;

            return $row;
        }, 3);
        if (!$row) {
            return false;
        }
        $settings = app(Settings::class);
        $post = $this->eligible($row->post_id);
        $url = app(Secrets::class)->get('forum.discord.webhook');
        if (
            !$post ||
            !$settings->get('forum.discord.enabled', false) ||
            $row->configuration_id !== $settings->get('forum.discord.configuration_id') ||
            !in_array($row->event, $settings->get('forum.discord.events', ['topic']), true) ||
            !WebhookClient::validDiscordUrl($url)
        ) {
            $this->finish($id, ['status' => 'cancelled']);

            return true;
        }
        try {
            $response = app(WebhookClient::class)->discord($url, [
                [
                    'title' => __('forum::admin.announcements.event_'.$row->event),
                    'description' => __('forum::admin.announcements.public_link'),
                    'url' => TopicUrl::forPost($post->topic_id, $post->id),
                ],
            ]);
            $values = ['status' => $response['status'], 'http_status' => $response['http_status']];
            if ($response['status'] === 'delivered') {
                $values += [
                    'delivered_at' => now(),
                    'message_id' => $response['message_id'],
                ];
            } elseif ($response['retry_after'] !== null && $row->attempts < 8) {
                $values['status'] = 'pending';
                $values['available_at'] = now()->addSeconds($response['retry_after']);
            }
            $this->finish($id, $values);
        } catch (Throwable) {
            $this->finish($id, ['status' => 'uncertain']);
        }

        return true;
    }

    private function finish(string $id, array $values): void
    {
        Announcement::query()
            ->where('id', $id)
            ->where('status', 'sending')
            ->update($values + ['updated_at' => now()]);
    }
}
