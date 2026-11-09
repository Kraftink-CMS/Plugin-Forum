<?php

namespace KraftInk\Plugins\Forum\Services;

use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Categories\CategoryRole;
use KraftInk\Plugins\Forum\Models\Topics\Tag;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Collection as NativeCollection;

class ForumAccess
{
    private array $identities = [];

    private ?array $categories = null;

    private ?array $categoryRoles = null;

    private ?Request $request = null;

    private function fresh(): void
    {
        if ($this->request !== request()) {
            $this->request = request();
            $this->identities = [];
            $this->categories = null;
            $this->categoryRoles = null;
        }
    }

    private function identity(?User $user): array
    {
        $this->fresh();
        $id = $user?->id ?? 0;
        if (!isset($this->identities[$id])) {
            $roles = $user?->effectiveRoles() ?? collect();
            $this->identities[$id] = [
                'roles' => $roles->pluck('id')->all(),
                'permissions' => app(PermissionAccess::class)->permissions($user, $roles),
            ];
        }

        return $this->identities[$id];
    }

    public function canModerate(?User $user): bool
    {
        $permissions = $this->identity($user)['permissions'];

        return count(array_intersect(['*', 'forum.manage', 'forum.moderate'], $permissions)) > 0;
    }

    public function permission(?User $user, ?string $permission): bool
    {
        return !$permission || count(array_intersect(['*', $permission], $this->identity($user)['permissions'])) > 0;
    }

    private function categories(): array
    {
        $this->fresh();
        if ($this->categories === null) {
            $this->categories = Category::query()->get()->keyBy('id')->all();
            $this->categoryRoles = CategoryRole::query()
                ->get()
                ->groupBy('category_id')
                ->map(
                    fn (Collection $rows) => $rows
                        ->groupBy('ability')
                        ->map(fn (Collection $roles) => $roles->pluck('role_id')->all())
                        ->all(),
                )
                ->all();
        }

        return $this->categories;
    }

    private function ability(?User $user, object $category, string $ability): bool
    {
        if (!$this->permission($user, $category->{$ability.'_permission'})) {
            return false;
        }

        return match ($category->{$ability.'_access'}) {
            'public' => true,
            'members' => $user !== null,
            'roles' => $user &&
                array_intersect(
                    $this->identity($user)['roles'],
                    $this->categoryRoles[$category->id][$ability] ?? [],
                ) !== [],
            default => false,
        };
    }

    public function canRead(?User $user, object $category): bool
    {
        if (!$this->permission($user, 'forum.view')) {
            return false;
        }
        $categories = $this->categories();
        $seen = [];
        while ($category) {
            if (isset($seen[$category->id]) || count($seen) >= 4 || !$this->ability($user, $category, 'read')) {
                return false;
            }
            $seen[$category->id] = true;
            if ($category->parent_id && !isset($categories[$category->parent_id])) {
                return false;
            }
            $category = $category->parent_id ? $categories[$category->parent_id] ?? null : null;
        }

        return true;
    }

    public function canWrite(?User $user, object $category): bool
    {
        if (
            !$user ||
            $user->anonymized_at ||
            $user->import_locked ||
            $user->isBanned() ||
            !$this->canRead($user, $category)
        ) {
            return false;
        }
        $categories = $this->categories();
        while ($category) {
            if (
                !$this->ability($user, $category, 'write') ||
                ($category->locked && !$this->permission($user, 'forum.bypass-lock'))
            ) {
                return false;
            }
            $category = $category->parent_id ? $categories[$category->parent_id] : null;
        }

        return true;
    }

    public function readableIds(?User $user, ?string $locale = null): array
    {
        $ids = [];
        foreach ($this->categories() as $category) {
            if (($locale === null || $category->locale === $locale) && $this->canRead($user, $category)) {
                $ids[] = $category->id;
            }
        }

        return $ids;
    }

    public function canIndex(?Category $category = null): bool
    {
        if (app(Settings::class)->get('forum.noindex', false)) {
            return false;
        }

        return $category === null
            ? $this->permission(null, 'forum.view')
            : !$category->private_topics && $this->canRead(null, $category);
    }

    public function indexableCategoryIds(string $locale): array
    {
        $ids = [];
        foreach ($this->categories() as $category) {
            if ($category->locale === $locale && $this->canIndex($category)) {
                $ids[] = $category->id;
            }
        }

        return $ids;
    }

    public function canReply(?User $user, object $category, object $topic): bool
    {
        return $this->canWrite($user, $category) && (!$topic->locked || $this->permission($user, 'forum.bypass-lock'));
    }

    public function canManageTopic(?User $user, object $category, object $topic): bool
    {
        return $user && ($this->canModerate($user) || $this->canEditOwnTopic($user, $category, $topic));
    }

    public function canEditOwnTopic(?User $user, object $category, object $topic): bool
    {
        return $user && (int) $topic->user_id === $user->id && !$topic->locked && $this->canWrite($user, $category);
    }

    public function canManagePost(?User $user, object $category, object $topic, object $post): bool
    {
        return $user &&
            ($this->canModerate($user) ||
                ((int) $post->user_id === $user->id && $this->canReply($user, $category, $topic)));
    }

    public function canDeleteAttachment(?User $user, object $category, object $topic, object $post): bool
    {
        return $user &&
            ($this->canModerate($user) ||
                ((int) $post->user_id === $user->id && !$topic->locked && $this->canWrite($user, $category)));
    }

    public function topics(?User $user, ?string $locale = null, bool $withDeleted = false): Builder
    {
        return Topic::query()->visibleTo($user, $locale, $withDeleted);
    }

    public function topic(?User $user, int $id, bool $lock = false, bool $withDeleted = false): Topic
    {
        $query = $this->topics($user, withDeleted: $withDeleted)->where('forum__topics.id', $id);
        $topic = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($topic === null) {
            abort(404);
        }

        return $topic;
    }

    public function tags(?User $user): NativeCollection
    {
        return Tag::query()->assignableTo($user)->orderBy('name')->get();
    }
}
