<?php

namespace KraftInk\Plugins\Forum\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $user_id
 * @property string|null $signature
 * @property string|null $bio
 * @property string|null $website
 * @property string|null $discord
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Profile extends Model
{
    protected $table = 'forum__profiles';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
