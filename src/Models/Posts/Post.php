<?php

namespace KraftInk\Plugins\Forum\Models\Posts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use KraftInk\Plugins\Forum\Models\Profile;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use KraftInk\Plugins\Forum\Services\ForumAccess;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $topic_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $hidden
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Profile|null $profile
 */
class Post extends Model
{
    protected $table = 'forum__posts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'topic_id' => 'integer',
            'user_id' => 'integer',
            'hidden' => 'boolean',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class, 'post_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'post_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class, 'post_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class, 'post_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'post_id');
    }

    public function announcement(): HasOne
    {
        return $this->hasOne(Announcement::class, 'post_id');
    }

    public function recordRevision(int $actor): PostRevision
    {
        return $this->revisions()->create([
            'user_id' => $actor,
            'body' => $this->body,
        ]);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id', 'user_id');
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return app(ForumAccess::class)->canModerate($user)
            ? $query->whereNull('forum__posts.deleted_at')
            : $query->published();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNull('forum__posts.deleted_at')->where('forum__posts.hidden', false);
    }
}
