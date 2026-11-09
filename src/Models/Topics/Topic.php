<?php

namespace KraftInk\Plugins\Forum\Models\Topics;

use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Polls\Poll;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Services\ForumAccess;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $user_id
 * @property string $title
 * @property bool $pinned
 * @property bool $locked
 * @property bool $hidden
 * @property int $views
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 * @property-read User|null $user
 * @property-read Poll|null $poll
 */
class Topic extends Model
{
    protected $table = 'forum__topics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'category_id' => 'integer',
            'user_id' => 'integer',
            'pinned' => 'boolean',
            'locked' => 'boolean',
            'hidden' => 'boolean',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'topic_id');
    }

    public function topicTags(): HasMany
    {
        return $this->hasMany(TopicTag::class, 'topic_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'topic_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'forum__topic_tags', 'topic_id', 'tag_id')->using(TopicTag::class);
    }

    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class, 'topic_id');
    }

    public function scopeVisibleTo(
        Builder $query,
        ?User $user,
        ?string $locale = null,
        bool $withDeleted = false,
    ): Builder {
        $access = app(ForumAccess::class);
        $query
            ->whereIn('category_id', $access->readableIds($user, $locale))
            ->when(!$withDeleted, fn ($query) => $query->whereNull('forum__topics.deleted_at'));
        if (!$access->canModerate($user)) {
            $query->where('forum__topics.hidden', false);
            $private = Category::query()->where('private_topics', true)->pluck('id')->all();
            $query->where(
                fn ($query) => $query
                    ->whereNotIn('category_id', $private)
                    ->when($user, fn ($query) => $query->orWhere('forum__topics.user_id', $user->id)),
            );
        }

        return $query;
    }
}
