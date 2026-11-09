<?php

namespace KraftInk\Plugins\Forum\Models\Topics;

use Illuminate\Database\Eloquent\Collection;
use App\Models\Accounts\User;
use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Forum\Models\Categories\CategoryTag;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property-read Collection<int, Role> $roles
 */
class Tag extends Model
{
    protected $table = 'forum__tags';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }

    public function topicTags(): HasMany
    {
        return $this->hasMany(TopicTag::class, 'tag_id');
    }

    public function categoryTags(): HasMany
    {
        return $this->hasMany(CategoryTag::class, 'tag_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'forum__tag_roles', 'tag_id', 'role_id')->using(TagRole::class);
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'forum__topic_tags', 'tag_id', 'topic_id')->using(TopicTag::class);
    }

    public function scopeAssignableTo(Builder $query, ?User $user): Builder
    {
        $roles = $user?->effectiveRoles()->pluck('id')->all() ?? [];

        return $query->where(
            fn (Builder $tags) => $tags
                ->whereDoesntHave('roles')
                ->orWhereHas('roles', fn (Builder $role) => $role->whereIn('roles.id', $roles)),
        );
    }
}
