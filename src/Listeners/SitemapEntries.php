<?php

namespace KraftInk\Plugins\Forum\Listeners;

use App\Events\CollectingSitemapEntries;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\TopicUrl;
use KraftInk\Plugins\Forum\Models\Topics\Topic;

class SitemapEntries
{
    public function collect(CollectingSitemapEntries $event): void
    {
        $access = app(ForumAccess::class);
        if (!$access->canIndex()) {
            return;
        }
        $event->add(route('plugins.forum.public.home.index'));
        $event->source(function () use ($event, $access): iterable {
            $categories = $access->indexableCategoryIds($event->locale);
            sort($categories, SORT_NUMERIC);
            foreach ($categories as $id) {
                yield ['url' => route('plugins.forum.public.categories.show', $id)];
            }
            foreach (
                Topic::query()
                    ->whereIn('category_id', $categories)
                    ->where('hidden', false)
                    ->whereNull('deleted_at')
                    ->select(['id', 'title', 'updated_at'])
                    ->orderBy('id')
                    ->cursor() as $topic
            ) {
                /** @var Topic $topic */
                yield ['url' => TopicUrl::for($topic), 'modified' => $topic->updated_at];
            }
        });
    }
}
