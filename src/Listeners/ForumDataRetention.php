<?php

namespace KraftInk\Plugins\Forum\Listeners;

use App\Events\PruningData;
use App\Kraft\System\RevisionRetention;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use KraftInk\Plugins\Forum\Models\Polls\PollRevision;
use KraftInk\Plugins\Forum\Models\Posts\PostRevision;
use KraftInk\Plugins\Forum\Services\ForumDrafts;
use KraftInk\Plugins\Forum\Services\ForumFiles;
use KraftInk\Plugins\Forum\Services\Notifications\ForumAnnouncements;

class ForumDataRetention
{
    public function prune(PruningData $event): void
    {
        $revisions = app(RevisionRetention::class);
        $event->add(
            'forum.post_revisions',
            $revisions->prune(
                PostRevision::query(),
                'post_id',
                $event->dryRun,
                fn (Builder|EloquentBuilder $query) => $query->whereNotExists(
                    fn (Builder $reports) => $reports
                        ->selectRaw('1')
                        ->from('forum__reports')
                        ->whereColumn('forum__reports.post_id', 'forum__post_revisions.post_id')
                        ->where('forum__reports.resolved', false),
                ),
            ),
        );
        $event->add(
            'forum.poll_revisions',
            $revisions->prune(
                PollRevision::query(),
                'poll_id',
                $event->dryRun,
                fn (Builder|EloquentBuilder $query) => $query->whereNotExists(
                    fn (Builder $reports) => $reports
                        ->selectRaw('1')
                        ->from('forum__reports')
                        ->join('forum__posts', 'forum__posts.id', '=', 'forum__reports.post_id')
                        ->join('forum__polls', 'forum__polls.topic_id', '=', 'forum__posts.topic_id')
                        ->whereColumn('forum__polls.id', 'forum__poll_revisions.poll_id')
                        ->where('forum__reports.resolved', false),
                ),
            ),
        );
        $event->add('forum.drafts', app(ForumDrafts::class)->cleanup($event->dryRun));
        $event->add('forum.attachments', app(ForumFiles::class)->cleanup(dryRun: $event->dryRun));
        $event->add('forum.announcements', app(ForumAnnouncements::class)->cleanup($event->dryRun));
    }
}
