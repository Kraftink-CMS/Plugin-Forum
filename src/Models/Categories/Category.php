<?php

namespace KraftInk\Plugins\Forum\Models\Categories;

use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Models\Topics\Tag;
use KraftInk\Plugins\Forum\Models\Topics\Topic;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property string $locale
 * @property string $icon
 * @property string $read_access
 * @property string|null $read_permission
 * @property string $write_access
 * @property string|null $write_permission
 * @property bool $locked
 * @property bool $private_topics
 * @property int $position
 * @property-read Category|null $parent
 * @property-read Collection<int, CategoryRole> $categoryRoles
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Collection<int, Role> $roles
 */
class Category extends Model
{
    protected $table = 'forum__categories';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'locked' => 'boolean',
            'private_topics' => 'boolean',
            'position' => 'integer',
            'id' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class, 'category_id');
    }

    public function categoryRoles(): HasMany
    {
        return $this->hasMany(CategoryRole::class, 'category_id');
    }

    public function categoryTags(): HasMany
    {
        return $this->hasMany(CategoryTag::class, 'category_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'category_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'forum__category_roles', 'category_id', 'role_id')
            ->using(CategoryRole::class)
            ->withPivot('ability');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'forum__category_tags', 'category_id', 'tag_id')->using(
            CategoryTag::class,
        );
    }

    public function readRoles(): BelongsToMany
    {
        return $this->roles()->withPivotValue('ability', 'read');
    }

    public function writeRoles(): BelongsToMany
    {
        return $this->roles()->withPivotValue('ability', 'write');
    }
}
