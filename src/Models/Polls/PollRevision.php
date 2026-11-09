<?php

namespace KraftInk\Plugins\Forum\Models\Polls;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $poll_id
 * @property int|null $user_id
 * @property array $snapshot
 * @property Carbon $created_at
 * @property-read Poll|null $poll
 * @property-read User|null $user
 */
class PollRevision extends Model
{
    protected $table = 'forum__poll_revisions';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'user_id' => 'integer',
            'snapshot' => 'array',
            'created_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
