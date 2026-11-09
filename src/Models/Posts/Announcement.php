<?php

namespace KraftInk\Plugins\Forum\Models\Posts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int $post_id
 * @property string $configuration_id
 * @property string|null $message_id
 * @property string $event
 * @property string $status
 * @property int $attempts
 * @property int|null $http_status
 * @property Carbon|null $available_at
 * @property Carbon|null $started_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Announcement extends Model
{
    use HasUuids;

    protected $table = 'forum__announcements';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'attempts' => 'integer',
            'post_id' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
