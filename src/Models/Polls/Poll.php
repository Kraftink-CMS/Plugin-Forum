<?php

namespace KraftInk\Plugins\Forum\Models\Polls;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Forum\Models\Topics\Topic;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $topic_id
 * @property string $question
 * @property int $max_choices
 * @property bool $allow_change_votes
 * @property bool $allow_withdraw_votes
 * @property string $results_visibility
 * @property bool $closed
 * @property Carbon|null $closes_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PollOption> $options
 */
class Poll extends Model
{
    protected $table = 'forum__polls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'max_choices' => 'integer',
            'topic_id' => 'integer',
            'allow_change_votes' => 'boolean',
            'allow_withdraw_votes' => 'boolean',
            'closed' => 'boolean',
            'closes_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(PollBallot::class, 'poll_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PollRevision::class, 'poll_id');
    }
}
