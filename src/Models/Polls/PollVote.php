<?php

namespace KraftInk\Plugins\Forum\Models\Polls;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $ballot_id
 * @property int $option_id
 */
class PollVote extends Model
{
    protected $table = 'forum__poll_votes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'ballot_id' => 'integer',
            'option_id' => 'integer',
            'id' => 'integer',
        ];
    }

    public function ballot(): BelongsTo
    {
        return $this->belongsTo(PollBallot::class, 'ballot_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
