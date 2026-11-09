<?php

namespace KraftInk\Plugins\Forum\Models\Posts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $post_id
 * @property int $user_id
 * @property string $reason
 * @property bool $resolved
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class Report extends Model
{
    protected $table = 'forum__reports';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'user_id' => 'integer',
            'resolved' => 'boolean',
            'created_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
