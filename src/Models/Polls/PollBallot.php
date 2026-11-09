<?php

namespace KraftInk\Plugins\Forum\Models\Polls;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $poll_id
 * @property int $user_id
 * @property Carbon $created_at
 * @property-read Poll|null $poll
 * @property-read User|null $user
 * @property-read Collection<int, PollVote> $votes
 */
class PollBallot extends Model
{
    protected $table = 'forum__poll_ballots';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'user_id' => 'integer',
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

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'ballot_id');
    }
}
