<?php

namespace KraftInk\Plugins\Forum\Models\Topics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $topic_id
 * @property int $tag_id
 */
class TopicTag extends Pivot
{
    protected $table = 'forum__topic_tags';

    protected $guarded = ['id'];

    public $timestamps = false;

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'topic_id' => 'integer',
            'tag_id' => 'integer',
            'id' => 'integer',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
