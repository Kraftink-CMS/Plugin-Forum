<?php

namespace KraftInk\Plugins\Forum\Models\Topics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $topic_id
 * @property int $user_id
 * @property int $last_read_post_id
 * @property int $last_notified_post_id
 * @property int|null $pending_post_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Subscription extends Model
{
    protected $table = 'forum__subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pending_post_id' => 'integer',
            'last_notified_post_id' => 'integer',
            'last_read_post_id' => 'integer',
            'topic_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
