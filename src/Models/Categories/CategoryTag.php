<?php

namespace KraftInk\Plugins\Forum\Models\Categories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use KraftInk\Plugins\Forum\Models\Topics\Tag;

/**
 * @mixin Builder<static>
 *
 * @property int $category_id
 * @property int $tag_id
 * @property-read Category|null $category
 */
class CategoryTag extends Pivot
{
    protected $table = 'forum__category_tags';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'tag_id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
