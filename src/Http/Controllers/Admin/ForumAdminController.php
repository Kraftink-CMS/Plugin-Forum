<?php

namespace KraftInk\Plugins\Forum\Http\Controllers\Admin;

use App\Kraft\System\TableQuery;
use App\Kraft\Audit;
use App\Kraft\Events\PluginEvents;
use App\Kraft\Html;
use App\Kraft\Settings;
use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Categories\CategoryRole;
use KraftInk\Plugins\Forum\Models\Categories\CategoryTag;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\Report;
use KraftInk\Plugins\Forum\Models\Topics\Tag;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\ForumFiles;
use KraftInk\Plugins\Forum\Services\ForumModeration;
use KraftInk\Plugins\Forum\Services\Notifications\ForumAnnouncements;
use KraftInk\Plugins\Forum\Services\Notifications\ForumMentions;
use KraftInk\Plugins\Forum\Services\Notifications\ForumNotifications;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use App\Kraft\LocalePolicy;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ForumAdminController
{
    public function __construct(private ForumAccess $access)
    {
    }

    private function authorize(Request $request, string $permission = 'forum.manage'): void
    {
        if (
            !($permission === 'forum.moderate'
                ? $this->access->canModerate($request->user())
                : $request->user()?->hasPermission('forum.manage') || $request->user()?->hasPermission($permission))
        ) {
            throw new HttpException(403);
        }
    }

    public function categories(Request $request, Settings $settings): View
    {
        $this->authorize($request);
        $query = Category::query()->orderBy('locale')->orderBy('parent_id')->orderBy('position')->orderBy('id');
        if ($request->filled('q')) {
            $query->whereLike('name', '%'.mb_substr($request->string('q'), 0, 100).'%');
        }
        $editing = $request->filled('edit') ? Category::query()->find($request->integer('edit')) : null;
        if ($request->filled('edit') && !$editing) {
            abort(404);
        }
        $parents = Category::query()->orderBy('locale')->orderBy('name')->get();
        $moving = $request->filled('move') ? $parents->find($request->integer('move')) : null;
        if ($request->filled('move') && !$moving) {
            abort(404);
        }

        return view('forum::admin.categories.index', [
            'categories' => TableQuery::paginate(
                $query,
                ['name' => 'name', 'locale' => 'locale', 'read_access' => 'read_access'],
                pageName: 'page',
                search: [],
            )->withQueryString(),
            'parents' => $parents,
            'siblings' => $parents->sortBy([['position', 'asc'], ['id', 'asc']])->groupBy(['locale', 'parent_id']),
            'moving' => $moving,
            'moveTargets' => $moving ? $this->moveTargets($moving, $parents->keyBy('id')) : [],
            'roles' => Role::query()->orderByDesc('position')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'editing' => $editing,
            'categoryRoles' => $editing
                ? CategoryRole::query()->where('category_id', $editing->id)->get()->groupBy('ability')
                : collect(),
            'defaultTags' => $editing
                ? CategoryTag::query()->where('category_id', $editing->id)->pluck('tag_id')->all()
                : [],
            'preferences' => [
                'welcome' => $settings->get('forum.welcome', []),
                'recent_count' => $settings->get('forum.recent_count', 10),
                'attachment_quota_mb' => $settings->get('forum.attachment_quota_mb', 64),
                'noindex' => $settings->get('forum.noindex', false),
                'topic_limit' => $settings->get('forum.topic_limit', 5),
                'reply_limit' => $settings->get('forum.reply_limit', 10),
                'post_interval' => $settings->get('forum.post_interval', 0),
            ],
        ]);
    }

    public function saveCategory(Request $request, ?int $id = null): RedirectResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'locale' => ['required', Rule::in(array_keys(config('kraft.locales')))],
            'position' => 'required|integer|between:0,10000',
            'parent_id' => 'nullable|integer|exists:forum__categories,id',
            'read_access' => ['sometimes', Rule::in(['public', 'members', 'roles'])],
            'write_access' => ['sometimes', Rule::in(['members', 'roles', 'none'])],
            'read_roles' => 'nullable|array|max:100',
            'read_roles.*' => 'integer|distinct|exists:roles,id',
            'write_roles' => 'nullable|array|max:100',
            'write_roles.*' => 'integer|distinct|exists:roles,id',
            'default_tags' => 'nullable|array|max:5',
            'default_tags.*' => 'integer|distinct|exists:forum__tags,id',
            'icon' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'max:60'],
            'locked' => 'sometimes|boolean',
            'private_topics' => 'sometimes|boolean',
            'replace_legacy_access' => 'sometimes|boolean',
        ]);
        $category = $id ? Category::query()->find($id) : null;
        if ($id && !$category) {
            abort(404);
        }
        if (!$id && Category::query()->count() >= 5000) {
            throw new HttpException(422);
        }
        foreach (['read', 'write'] as $ability) {
            if (
                ($data[$ability.'_access'] ?? ($ability === 'read' ? 'public' : 'members')) === 'roles' &&
                empty($data[$ability.'_roles'])
            ) {
                throw ValidationException::withMessages([
                    $ability.'_roles' => __('forum::admin.categories.roles_required'),
                ]);
            }
        }
        $attributes = array_intersect_key(
            $data,
            array_flip([
                'name',
                'description',
                'locale',
                'position',
                'parent_id',
                'read_access',
                'write_access',
                'icon',
            ]),
        );
        $attributes += ['parent_id' => null, 'read_access' => 'public', 'write_access' => 'members'];
        $attributes['icon'] = $data['icon'] ?? null ?: 'chat-left-text';
        $attributes['locked'] = $request->boolean('locked');
        $attributes['private_topics'] = $request->boolean('private_topics');
        if (!$category || $request->boolean('replace_legacy_access')) {
            $attributes['read_permission'] = null;
            $attributes['write_permission'] = null;
        }
        DB::transaction(function () use ($id, $attributes, $data) {
            $categories = Category::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($id && !$categories->has($id)) {
                abort(404);
            }
            $this->parent($categories, $id, $data['parent_id'] ?? null, $data['locale']);
            if ($id) {
                Category::query()->where('id', $id)->update($attributes);
            } else {
                $id = Category::query()->insertGetId($attributes);
            }
            $category = Category::query()->findOrFail($id);
            $category->readRoles()->sync($data['read_roles'] ?? []);
            $category->writeRoles()->sync($data['write_roles'] ?? []);
            $category->tags()->sync($data['default_tags'] ?? []);
            Audit::record('forum.category_saved', ['id' => $id]);
        });

        return redirect()
            ->route('plugins.forum.admin.categories.index')
            ->with('status', __('forum::messages.common.saved'));
    }

    private function parent(Collection $categories, ?int $id, ?int $parent, string $locale): void
    {
        if (
            $id &&
            $categories->contains(fn ($category) => (int) $category->parent_id === $id && $category->locale !== $locale)
        ) {
            throw ValidationException::withMessages([
                'locale' => __('forum::admin.categories.category_language_in_use'),
            ]);
        }
        if ($parent && ($parent === $id || !isset($categories[$parent]) || $categories[$parent]->locale !== $locale)) {
            throw ValidationException::withMessages(['parent_id' => __('forum::admin.categories.invalid_parent')]);
        }
        $categories = $categories->all();
        $categories[$id ?? -1] = (object) ['id' => $id ?? -1, 'parent_id' => $parent];
        foreach ($categories as $category) {
            $seen = [];
            while ($category) {
                if (
                    isset($seen[$category->id]) ||
                    count($seen) >= 4 ||
                    ($category->parent_id && !isset($categories[$category->parent_id]))
                ) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('forum::admin.categories.invalid_parent'),
                    ]);
                }
                $seen[$category->id] = true;
                $category = $category->parent_id ? $categories[$category->parent_id] : null;
            }
        }
    }

    private function moveTargets(Category $moving, Collection $categories): Collection
    {
        $paths = $categories->where('locale', $moving->locale)->map(function (Category $category) use ($categories) {
            $path = [];
            while ($category && count($path) < 4) {
                $path[] = $category->id;
                $category = $categories->get($category->parent_id);
            }

            return $path;
        });
        $height = $paths->max(function (array $path) use ($moving): int {
            $distance = array_search($moving->id, $path, true);

            return $distance === false ? 0 : $distance + 1;
        });

        $targets = $paths->filter(fn (array $path) => count($path) + $height <= 4 && !in_array($moving->id, $path, true));

        return $categories->only($targets->keys()->all());
    }

    public function placeCategory(Request $request, int $id): RedirectResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'placement' => ['required', Rule::in(['up', 'down', 'parent'])],
            'parent_id' => 'nullable|integer|min:1',
        ]);
        DB::transaction(function () use ($data, $id): void {
            $categories = Category::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $category = $categories->get($id);
            if (!$category) {
                abort(404);
            }
            $reparent = $data['placement'] === 'parent';
            $parent = $reparent ? (isset($data['parent_id']) ? (int) $data['parent_id'] : null) : $category->parent_id;
            if ($reparent) {
                if ($parent === $category->parent_id) {
                    return;
                }
                $this->parent($categories, $id, $parent, $category->locale);
            }
            $ordered = $categories->where('locale', $category->locale)
                ->whereStrict('parent_id', $parent)
                ->sortBy([['position', 'asc'], ['id', 'asc']])->values();
            if ($reparent) {
                $category->parent_id = $parent;
                $ordered->push($category);
            } else {
                $index = $ordered->search(fn (Category $sibling) => $sibling->id === $id);
                $target = $index + ($data['placement'] === 'up' ? -1 : 1);
                if ($index === false || !$ordered->has($target)) {
                    return;
                }
                [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            }
            foreach ($ordered as $position => $sibling) {
                $sibling->update(['position' => $position]);
            }
            Audit::record('forum.category_placed', ['id' => $id]);
        }, 3);

        return to_route(
            'plugins.forum.admin.categories.index',
            $request->only(['q', 'page', 'page_sort', 'page_direction', 'page_per_page']),
        )->with('status', __('forum::messages.common.saved'));
    }

    public function deleteCategory(Request $request, int $id): RedirectResponse
    {
        $this->authorize($request);
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($id): void {
            Category::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if (!Category::query()->where('id', $id)->exists()) {
                abort(404);
            }
            if (
                Topic::query()->where('category_id', $id)->exists() ||
                Category::query()->where('parent_id', $id)->exists()
            ) {
                throw new HttpException(422, __('forum::admin.categories.category_used'));
            }
            Category::query()->where('id', $id)->delete();
            Audit::record('forum.category_deleted', ['id' => $id]);
        }, 3);

        return redirect()->route('plugins.forum.admin.categories.index');
    }

    public function topics(Request $request): RedirectResponse|View
    {
        $this->authorize($request, 'forum.moderate');
        if ($request->input('state') === 'deleted') {
            return redirect()->route('admin.trash.index', ['source' => 'forum.topics']);
        }
        $query = $this->access
            ->topics($request->user(), withDeleted: true)
            ->leftJoin('users', 'users.id', '=', 'forum__topics.user_id')
            ->join('forum__categories', 'forum__categories.id', '=', 'forum__topics.category_id')
            ->select('forum__topics.*', 'users.username as author_username', 'forum__categories.name as category_name');
        $query->whereNull('forum__topics.deleted_at');
        if ($request->filled('q')) {
            $query->whereLike('title', '%'.mb_substr($request->string('q'), 0, 100).'%');
        }
        if ($request->integer('category')) {
            $query->where('category_id', $request->integer('category'));
        }
        if (in_array($request->input('state'), ['hidden', 'locked', 'pinned'], true)) {
            $query->where('forum__topics.'.$request->input('state'), true);
        }
        $editing = $request->filled('edit') ? $this->access->topic($request->user(), $request->integer('edit')) : null;

        return view('forum::admin.topics.index', [
            'topics' => TableQuery::paginate(
                $query->orderByDesc('forum__topics.updated_at'),
                ['title' => 'forum__topics.title', 'category' => 'forum__categories.name', 'author' => 'users.username'],
                pageName: 'page',
                search: [],
            )->withQueryString(),
            'categories' => Category::query()
                ->whereIn('id', $this->access->readableIds($request->user()))
                ->orderBy('name')
                ->get(),
            'editing' => $editing,
        ]);
    }

    public function createTopic(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'category_id' => 'required|integer|exists:forum__categories,id',
            'title' => 'required|string|min:3|max:180',
            'body' => 'required|string|min:3|max:50000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:5120',
        ]);
        if (!in_array((int) $data['category_id'], $this->access->readableIds($request->user()), true)) {
            abort(404);
        }
        $data['body'] = Html::clean($data['body']);
        $text = html_entity_decode(strip_tags($data['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) && !preg_match('/<img\b/i', $data['body'])) {
            throw ValidationException::withMessages(['body' => __('forum::messages.history.empty_body')]);
        }
        $topic = DB::transaction(function () use ($request, $data) {
            $topic = Topic::query()->insertGetId([
                'category_id' => $data['category_id'],
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $post = Post::query()->insertGetId([
                'topic_id' => $topic,
                'user_id' => $request->user()->id,
                'body' => $data['body'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(ForumFiles::class)->bind(
                $post,
                $request->user(),
                (int) $data['category_id'],
                $data['body'],
                $data['attachments'] ?? [],
            );
            app(ForumMentions::class)->enqueue($post, $request->user()->id, $data['body']);
            app(ForumNotifications::class)->follow($topic, $request->user()->id, true, $post);
            app(ForumAnnouncements::class)->enqueue($post, 'topic');
            $events = app(PluginEvents::class);
            $events->publish(
                'forum.topic.published',
                [
                    'topic_id' => $topic,
                    'post_id' => $post,
                    'category_id' => (int) $data['category_id'],
                    'user_id' => (int) $request->user()->id,
                ],
                'forum.topic.published:'.$topic,
            );
            $events->publish(
                'forum.post.published',
                [
                    'topic_id' => $topic,
                    'post_id' => $post,
                    'category_id' => (int) $data['category_id'],
                    'user_id' => (int) $request->user()->id,
                    'is_topic_start' => true,
                ],
                'forum.post.published:'.$post,
            );
            Audit::record('forum.topic_created', ['id' => $topic]);

            return $topic;
        });

        return redirect()->route('plugins.forum.admin.topics.index', ['edit' => $topic]);
    }

    public function moderate(Request $request, int $id, ForumModeration $moderation): RedirectResponse
    {
        $this->authorize($request, 'forum.moderate');
        $this->access->topic($request->user(), $id);
        $data = $request->validate([
            'title' => 'sometimes|required|string|min:3|max:180',
            'category_id' => 'sometimes|required|integer|exists:forum__categories,id',
            'locked' => 'sometimes|boolean',
            'pinned' => 'sometimes|boolean',
            'hidden' => 'sometimes|boolean',
        ]);
        if (
            isset($data['category_id']) &&
            !in_array((int) $data['category_id'], $this->access->readableIds($request->user()), true)
        ) {
            abort(404);
        }
        $moderation->updateTopic($request->user(), $id, $data);

        return redirect()
            ->route('plugins.forum.admin.topics.index')
            ->with('status', __('forum::messages.common.saved'));
    }

    public function deleteTopic(Request $request, int $id): RedirectResponse
    {
        $this->authorize($request, 'forum.moderate');
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id): void {
            $this->access->topic($request->user(), $id, lock: true);
            Topic::query()
                ->where('id', $id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            Audit::record('forum.topic_removed', ['id' => $id]);
        }, 3);

        return redirect()->route('plugins.forum.admin.topics.index');
    }

    public function reports(Request $request): View
    {
        $this->authorize($request, 'forum.moderate');
        $topics = $this->access->topics($request->user(), withDeleted: true)->select('forum__topics.id');
        $query = Report::query()
            ->join('forum__posts', 'forum__posts.id', '=', 'forum__reports.post_id')
            ->join('forum__topics', 'forum__topics.id', '=', 'forum__posts.topic_id')
            ->whereIn('forum__topics.id', $topics)
            ->select(
                'forum__reports.*',
                'forum__posts.body',
                'forum__posts.hidden',
                'forum__posts.deleted_at as post_deleted_at',
                'forum__topics.title',
                'forum__topics.deleted_at as topic_deleted_at',
                'forum__posts.topic_id',
            )
            ->where('resolved', $request->input('state') === 'resolved');

        return view('forum::admin.reports.index', [
            'reports' => TableQuery::paginate(
                $query->orderByDesc('forum__reports.id'),
                ['title' => 'forum__topics.title', 'reason' => 'forum__reports.reason'],
                pageName: 'page',
                search: ['forum__topics.title', 'forum__reports.reason'],
            )->withQueryString(),
        ]);
    }

    public function resolveReport(Request $request, int $id, ForumModeration $moderation): RedirectResponse
    {
        $this->authorize($request, 'forum.moderate');
        $moderation->resolveReport($request->user(), $id);

        return back();
    }

    public function moderatePost(Request $request, int $id, ForumModeration $moderation): RedirectResponse
    {
        $this->authorize($request, 'forum.moderate');
        $request->validate(['hidden' => 'required|boolean']);
        $moderation->setPostHidden($request->user(), $id, $request->boolean('hidden'));

        return back();
    }

    public function tags(Request $request): View
    {
        $this->authorize($request, 'forum.tags');
        $editing = $request->filled('edit') ? Tag::query()->find($request->integer('edit')) : null;
        if ($request->filled('edit') && !$editing) {
            abort(404);
        }

        return view('forum::admin.tags.index', [
            'tags' => TableQuery::paginate(
                Tag::query()->orderBy('name'),
                ['name' => 'name', 'slug' => 'slug'],
                pageName: 'page',
                search: ['name', 'slug'],
            ),
            'roles' => Role::query()->orderByDesc('position')->get(),
            'editing' => $editing,
            'selectedRoles' => $editing?->roles()->allRelatedIds()->all() ?? [],
        ]);
    }

    public function saveTag(Request $request, ?int $id = null): RedirectResponse
    {
        $this->authorize($request, 'forum.tags');
        $request->merge(['slug' => $request->input('slug') ?: Str::slug((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'slug' => ['required', 'alpha_dash:ascii', 'max:80', Rule::unique('forum__tags')->ignore($id)],
            'color' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'roles' => 'nullable|array|max:100',
            'roles.*' => 'integer|distinct|exists:roles,id',
        ]);
        if ($id && !Tag::query()->where('id', $id)->exists()) {
            abort(404);
        }
        DB::transaction(function () use ($data, $id) {
            $attributes = array_intersect_key($data, array_flip(['name', 'slug', 'color']));
            if ($id) {
                Tag::query()->where('id', $id)->update($attributes);
            } else {
                $id = Tag::query()->insertGetId($attributes);
            }
            Tag::query()
                ->findOrFail($id)
                ->roles()
                ->sync($data['roles'] ?? []);
        });

        return redirect()->route('plugins.forum.admin.tags.index')->with('status', __('forum::messages.common.saved'));
    }

    public function deleteTag(Request $request, int $id): RedirectResponse
    {
        $this->authorize($request, 'forum.tags');
        $request->validate(['confirmed' => 'accepted']);
        Tag::query()->where('id', $id)->delete();

        return redirect()->route('plugins.forum.admin.tags.index');
    }

    public function preferences(Request $request, Settings $settings): RedirectResponse
    {
        $this->authorize($request);
        $policy = app(LocalePolicy::class);
        $locales = $policy->allowsChoice() ? array_keys(config('kraft.locales')) : [$policy->defaultLocale()];
        $data = $request->validate([
            'welcome' => 'nullable|array:'.implode(',', $locales),
            'welcome.*' => 'nullable|string|max:50000',
            'recent_count' => 'required|integer|between:0,50',
            'attachment_quota_mb' => 'required|integer|between:8,2048',
            'noindex' => 'required|boolean',
            'topic_limit' => 'sometimes|integer|between:1,100',
            'reply_limit' => 'sometimes|integer|between:1,100',
            'post_interval' => 'sometimes|integer|between:0,300',
        ]);
        $welcome = $settings->get('forum.welcome', []);
        foreach ($locales as $locale) {
            if (array_key_exists($locale, $data['welcome'] ?? [])) {
                $welcome[$locale] = Html::clean($data['welcome'][$locale] ?? '');
            }
        }
        DB::transaction(function () use ($settings, $data, $welcome, $request) {
            $settings->set('forum.welcome', $welcome);
            $settings->set('forum.recent_count', (int) $data['recent_count']);
            $settings->set('forum.attachment_quota_mb', (int) $data['attachment_quota_mb']);
            $settings->set('forum.noindex', $request->boolean('noindex'));
            foreach (['topic_limit', 'reply_limit', 'post_interval'] as $key) {
                if (isset($data[$key])) {
                    $settings->set('forum.'.$key, (int) $data[$key]);
                }
            }
            Audit::record('forum.preferences_saved');
        });

        return back()->with('status', __('forum::messages.common.saved'));
    }
}
