<?php

namespace KraftInk\Plugins\Forum\Services;

use Illuminate\Support\Str;

class TopicUrl
{
    public static function for(object $topic): string
    {
        return route('plugins.forum.public.topics.show', [
            'id' => $topic->id,
            'slug' => Str::slug($topic->title) ?: 'discussion',
        ]);
    }

    public static function forPost(int $topicId, int $postId): string
    {
        return route('plugins.forum.public.topics.show', ['id' => $topicId, 'post' => $postId]).'#post-'.$postId;
    }
}
