<?php

namespace KraftInk\Plugins\Forum\Services;

use App\Kraft\Audit;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\Report;

class ForumModeration
{
    public function __construct(private ForumAccess $access)
    {
    }

    public function updateTopic(User $actor, int $id, array $attributes): void
    {
        DB::transaction(function () use ($actor, $id, $attributes): void {
            $topic = $this->access->topic($actor, $id, lock: true);
            $topic->update($attributes);
            Audit::record('forum.topic_moderated', ['id' => $id, 'changes' => array_keys($attributes)], $actor);
        }, 3);
    }

    public function setPostHidden(User $actor, int $id, bool $hidden): void
    {
        DB::transaction(function () use ($actor, $id, $hidden): void {
            $topicId = Post::query()->whereKey($id)->value('topic_id');
            if ($topicId === null) {
                abort(404);
            }
            $topic = $this->access->topic($actor, (int) $topicId, lock: true);
            $post = $topic->posts()->whereNull('deleted_at')->lockForUpdate()->find($id);
            if ($post === null) {
                abort(404);
            }
            $post->update(['hidden' => $hidden]);
            $post->reports()->update(['resolved' => true]);
            Audit::record('forum.post_moderated', ['id' => $id], $actor);
        }, 3);
    }

    public function resolveReport(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $topicId = Report::query()
                ->join('forum__posts', 'forum__posts.id', '=', 'forum__reports.post_id')
                ->where('forum__reports.id', $id)
                ->value('forum__posts.topic_id');
            if ($topicId === null) {
                abort(404);
            }
            $this->access->topic($actor, (int) $topicId, lock: true, withDeleted: true);
            $report = Report::query()
                ->whereHas('post', fn ($query) => $query->where('topic_id', $topicId))
                ->lockForUpdate()
                ->find($id);
            if ($report === null) {
                abort(404);
            }
            if (!$report->resolved) {
                $report->update(['resolved' => true]);
                Audit::record('forum.report_resolved', ['id' => $id], $actor);
            }
        }, 3);
    }
}
