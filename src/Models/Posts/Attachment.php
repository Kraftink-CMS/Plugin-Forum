<?php

namespace KraftInk\Plugins\Forum\Models\Posts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Forum\Models\Categories\Category;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $post_id
 * @property int|null $category_id
 * @property int|null $user_id
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $checksum
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read Category|null $category
 * @property-read User|null $user
 */
class Attachment extends Model
{
    protected $table = 'forum__attachments';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'post_id' => 'integer',
            'category_id' => 'integer',
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
