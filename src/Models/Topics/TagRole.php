<?php

namespace KraftInk\Plugins\Forum\Models\Topics;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @mixin Builder<static>
 *
 * @property int $tag_id
 * @property int $role_id
 */
class TagRole extends Pivot
{
    protected $table = 'forum__tag_roles';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tag_id' => 'integer',
            'role_id' => 'integer',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
