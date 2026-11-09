<?php

namespace KraftInk\Plugins\Forum\Models\Polls;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $poll_id
 * @property string $label
 * @property int $position
 * @property-read Poll|null $poll
 * @property-read Collection<int, PollVote> $votes
 */
class PollOption extends Model
{
    protected $table = 'forum__poll_options';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'poll_id' => 'integer',
            'id' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'option_id');
    }
}
