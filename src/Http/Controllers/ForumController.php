<?php

namespace KraftInk\Plugins\Forum\Http\Controllers;

use App\Kraft\Audit;
use App\Kraft\Auth\Avatars;
use App\Kraft\System\TableQuery;
use App\Kraft\Html;
use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Kraft\Site\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Categories\CategoryTag;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Models\Posts\Mention;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\PostLike;
use KraftInk\Plugins\Forum\Models\Posts\Report;
use KraftInk\Plugins\Forum\Models\Topics\Subscription;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use KraftInk\Plugins\Forum\Models\Topics\TopicTag;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\ForumDrafts;
use KraftInk\Plugins\Forum\Services\ForumFiles;
use KraftInk\Plugins\Forum\Services\ForumModeration;
use KraftInk\Plugins\Forum\Services\ForumPolls;
use KraftInk\Plugins\Forum\Services\Notifications\ForumAnnouncements;
use KraftInk\Plugins\Forum\Services\Notifications\ForumMentions;
use KraftInk\Plugins\Forum\Services\Notifications\ForumNotifications;
use KraftInk\Plugins\Forum\Services\TopicUrl;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use App\Kraft\Events\PluginEvents;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ForumController
{
    private const POSTS_PER_PAGE = 20;

    public function __construct(
        private ForumAccess $access,
        private ForumNotifications $notifications,
        private ForumPolls $polls,
    ) {
    }

    public function index(Request $request): View
    {
        $categories = Category::query()
            ->whereIn('id', $this->access->readableIds($request->user(), app()->getLocale()))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $topics = $this->access->topics($request->user(), app()->getLocale());
        $recent = max(0, min(50, (int) app(Settings::class)->get('forum.recent_count', 10)));
        $messages = app(Settings::class)->get('forum.welcome', []);
        $welcome = '';
        $policy = app(LocalePolicy::class);
        $locales = $policy->allowsChoice()
            ? array_unique([app()->getLocale(), 'en', $policy->defaultLocale()])
            : [$policy->defaultLocale()];
        foreach ($locales as $locale) {
            $message = Html::clean($messages[$locale] ?? '');
            $content = html_entity_decode(strip_tags($message, '<img><hr>'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim(str_replace("\u{00A0}", ' ', $content)) !== '') {
                $welcome = $message;
                break;
            }
        }

        return view('forum::default.home.index', [
            'categories' => $categories,
            'latestTopics' => $recent ? (clone $topics)->orderByDesc('updated_at')->limit($recent)->get() : collect(),
            'welcome' => $welcome,
            'seo' => [
                'excerpt' => $welcome,
                'robots' => $this->access->canIndex() ? '' : Seo::NO_INDEX,
            ],
            'seoVariables' => ['categories_count' => $categories->count()],
            'topicCounts' => (clone $topics)
                ->selectRaw('category_id,count(*) as total')
                ->groupBy('category_id')
                ->pluck('total', 'category_id'),
        ]);
    }

    public function search(Request $request): View
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'tag' => 'nullable|integer',
            'category' => 'nullable|integer',
        ]);
        $term = '%'.$data['q'].'%';
        $matchingPosts = Post::query()
            ->published()
            ->whereColumn('topic_id', 'forum__topics.id')
            ->whereLike('body', $term);
        $topics = $this->access->topics($request->user(), app()->getLocale())
            ->addSelect([
                'forum__topics.*',
                'matching_post_id' => (clone $matchingPosts)->orderBy('id')->limit(1)->select('id'),
            ])
            ->where(fn ($query) => $query->whereLike('title', $term)->orWhereExists($matchingPosts->select('id')));
        if (!empty($data['category'])) {
            $topics->where('category_id', $data['category']);
        }
        $tags = $this->access->tags($request->user());
        if (!empty($data['tag'])) {
            if (!$tags->contains('id', (int) $data['tag'])) {
                abort(404);
            }
            $topics->whereExists(
                fn ($query) => $query
                    ->selectRaw('1')
                    ->from('forum__topic_tags')
                    ->whereColumn('topic_id', 'forum__topics.id')
                    ->where('tag_id', $data['tag']),
            );
        }

        return view('forum::default.search.index', [
            'topics' => TableQuery::paginate(
                $topics->orderByDesc('updated_at'),
                [
                    'title' => 'forum__topics.title',
                    'views' => 'forum__topics.views',
                    'updated_at' => 'forum__topics.updated_at',
                ],
                pageName: 'page',
                search: [],
            )->withQueryString(),
            'categories' => Category::query()
                ->whereIn('id', $this->access->readableIds($request->user(), app()->getLocale()))
                ->orderBy('name')
                ->get(),
            'tags' => $tags,
        ]);
    }

    public function category(Request $request, int $id): View
    {
        $category = $this->categoryRecord($request, $id);
        $query = $this->access
            ->topics($request->user())
            ->leftJoin('users', 'users.id', '=', 'forum__topics.user_id')
            ->where('category_id', $id)
            ->select('forum__topics.*', 'users.username');
        $tags = $this->access->tags($request->user());
        if ($request->filled('tag')) {
            if (!$tags->contains('id', $request->integer('tag'))) {
                abort(404);
            }
            $query->whereExists(
                fn ($query) => $query
                    ->selectRaw('1')
                    ->from('forum__topic_tags')
                    ->whereColumn('topic_id', 'forum__topics.id')
                    ->where('tag_id', $request->integer('tag')),
            );
        }
        $topics = TableQuery::paginate(
            $query->orderByDesc('pinned')->orderByDesc('updated_at'),
            [
                'title' => 'forum__topics.title',
                'views' => 'forum__topics.views',
                'updated_at' => 'forum__topics.updated_at',
            ],
            pageName: 'page',
            search: [],
        )->withQueryString();

        return view('forum::default.categories.show', [
            'draft' => app(ForumDrafts::class)->get($request->user(), 'category_'.$id),
            'category' => $category,
            'topics' => $topics,
            'seo' => [
                'excerpt' => $category->description,
                'robots' => $this->access->canIndex($category) ? '' : Seo::NO_INDEX,
            ],
            'seoVariables' => [
                'category' => $category->name,
                'category_description' => $category->description,
                'topics_count' => $topics->total(),
            ],
            'children' => Category::query()
                ->where('parent_id', $id)
                ->whereIn('id', $this->access->readableIds($request->user()))
                ->orderBy('position')
                ->get(),
            'tags' => $tags,
            'topicTags' => TopicTag::query()
                ->join('forum__tags', 'forum__tags.id', '=', 'forum__topic_tags.tag_id')
                ->whereIn('topic_id', $topics->pluck('id'))
                ->whereIn('forum__tags.id', $tags->pluck('id'))
                ->select('forum__tags.*', 'topic_id')
                ->get()
                ->groupBy('topic_id'),
            'defaultTags' => CategoryTag::query()->where('category_id', $id)->pluck('tag_id')->all(),
            'canWrite' => $this->access->canWrite($request->user(), $category),
        ]);
    }

    public function topic(Request $request, int $id, ?string $slug = null): RedirectResponse|View
    {
        $topic = $this->access->topic($request->user(), $id);
        $category = $this->categoryRecord($request, $topic->category_id);
        $query = $topic
            ->posts()
            ->visibleTo($request->user())
            ->with(['user', 'profile'])
            ->orderBy('forum__posts.id');
        $moderator = $this->access->canModerate($request->user());
        $page = null;
        if ($request->has('post')) {
            $target = filter_var($request->query('post'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $page = 1;
            if ($target !== false && (clone $query)->where('forum__posts.id', $target)->exists()) {
                $preceding = (clone $query)->where('forum__posts.id', '<', $target)->count();
                $page = intdiv($preceding, self::POSTS_PER_PAGE) + 1;
            }
        } elseif ($request->boolean('last')) {
            return redirect()->route('plugins.forum.public.topics.show', [
                'id' => $id,
                'page' => max(1, (int) ceil((clone $query)->count() / self::POSTS_PER_PAGE)),
            ]);
        }
        $posts = $query->paginate(self::POSTS_PER_PAGE, page: $page);
        app(Avatars::class)->prepare($posts->getCollection()->pluck('user'));
        $canReply = $this->access->canReply($request->user(), $category, $topic);
        $canManageTopic = $this->access->canManageTopic($request->user(), $category, $topic);
        foreach ($posts as $post) {
            $post->username = $post->user?->username;
            $post->signature = $post->profile?->signature;
            $post->canLike = $request->user() && $request->user()->id !== (int) $post->user_id && !$post->hidden;
            $post->canReport = $request->user() && !$post->hidden;
            $post->canManage = $this->access->canManagePost($request->user(), $category, $topic, $post);
            $post->canDeleteAttachment = $this->access->canDeleteAttachment($request->user(), $category, $topic, $post);
        }
        $seen = $request->session()->get('forum.views', []);
        if (($seen[$id] ?? 0) < time() - 1800) {
            Topic::query()->where('id', $id)->increment('views');
            $seen[$id] = time();
            $request->session()->put('forum.views', array_slice($seen, -100, null, true));
        }
        if ($request->user() && $posts->isNotEmpty()) {
            $this->notifications->read($id, $request->user()->id, (int) $posts->max('id'));
        }

        return view(
            'forum::default.topics.show',
            [
                'draft' => app(ForumDrafts::class)->get($request->user(), 'topic_'.$id),
                'topic' => $topic,
                'category' => $category,
                'posts' => $posts,
                'seo' => [
                    'excerpt' => $posts->first(fn (Post $post) => !$post->hidden)?->body,
                    'canonical' => TopicUrl::for($topic),
                    'robots' => !$topic->hidden && $this->access->canIndex($category) ? '' : Seo::NO_INDEX,
                ],
                'seoVariables' => [
                    'topic' => $topic->title,
                    'category' => $category->name,
                    'posts_count' => $posts->total(),
                ],
                'moderator' => $moderator,
                'canRenameTopic' => $this->access->canEditOwnTopic($request->user(), $category, $topic),
                'canWrite' => $canReply,
                'canFollow' => $request->user() !== null,
                'canCreatePoll' => $canManageTopic && !$topic->locked,
                'tags' => $this->access->tags($request->user()),
                'selectedTags' => TopicTag::query()->where('topic_id', $id)->pluck('tag_id')->all(),
                'likeCounts' => PostLike::query()
                    ->whereIn('post_id', $posts->pluck('id'))
                    ->selectRaw('post_id,count(*) as aggregate')
                    ->groupBy('post_id')
                    ->pluck('aggregate', 'post_id'),
                'likedPosts' => $request->user()
                    ? PostLike::query()
                        ->where('user_id', $request->user()->id)
                        ->whereIn('post_id', $posts->pluck('id'))
                        ->pluck('post_id')
                        ->all()
                    : [],
                'following' => $request->user() &&
                    Subscription::query()->where('topic_id', $id)->where('user_id', $request->user()->id)->exists(),
                'canManageTopic' => $canManageTopic,
            ] + $this->topicExtras($request, $topic, $posts, $canReply),
        );
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $category = $this->categoryRecord($request, $id);
        if (!$this->access->canWrite($request->user(), $category)) {
            throw new HttpException(403);
        }
        $data = $request->validate([
            'title' => 'required|string|min:3|max:180',
            'body' => 'required|string|min:3|max:50000',
            'tags' => 'nullable|array|max:5',
            'tags.*' => 'integer|distinct',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:5120',
        ]);
        $assignableTags = $this->access->tags($request->user())->modelKeys();
        $tags =
            $data['tags'] ??
            array_values(
                array_intersect(
                    CategoryTag::query()->where('category_id', $id)->pluck('tag_id')->all(),
                    $assignableTags,
                ),
            );
        $this->validateTags($tags, $assignableTags);
        $topic = DB::transaction(function () use ($request, $data, $id, $tags) {
            $topic = Topic::query()->insertGetId([
                'category_id' => $id,
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                'pinned' => false,
                'locked' => false,
                'hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $post = Post::query()->insertGetId([
                'topic_id' => $topic,
                'user_id' => $request->user()->id,
                'body' => $this->body($data['body']),
                'hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(ForumFiles::class)->bind($post, $request->user(), $id, $data['body'], $data['attachments'] ?? []);
            app(ForumMentions::class)->enqueue($post, $request->user()->id, $data['body']);
            Topic::query()->findOrFail($topic)->tags()->sync($tags);
            $this->notifications->follow($topic, $request->user()->id, $request->boolean('follow', true), $post);
            app(ForumAnnouncements::class)->enqueue($post, 'topic');
            app(ForumDrafts::class)->forget($request->user()->id, 'category_'.$id);
            $events = app(PluginEvents::class);
            $events->publish(
                'forum.topic.published',
                [
                    'topic_id' => $topic,
                    'post_id' => $post,
                    'category_id' => $id,
                    'user_id' => (int) $request->user()->id,
                ],
                'forum.topic.published:'.$topic,
            );
            $events->publish(
                'forum.post.published',
                [
                    'topic_id' => $topic,
                    'post_id' => $post,
                    'category_id' => $id,
                    'user_id' => (int) $request->user()->id,
                    'is_topic_start' => true,
                ],
                'forum.post.published:'.$post,
            );

            return $topic;
        });

        return redirect()->route('plugins.forum.public.topics.show', $topic);
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string|min:2|max:50000',
            'follow' => 'sometimes|boolean',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:5120',
        ]);
        $post = DB::transaction(function () use ($request, $data, $id) {
            $topic = $this->access->topic($request->user(), $id, lock: true);
            $category = $this->categoryRecord($request, $topic->category_id);
            if ($topic->locked && !$this->access->permission($request->user(), 'forum.bypass-lock')) {
                throw new HttpException(422, __('forum::messages.discussions.locked'));
            }
            if (!$this->access->canWrite($request->user(), $category)) {
                throw new HttpException(403);
            }
            $post = Post::query()->insertGetId([
                'topic_id' => $id,
                'user_id' => $request->user()->id,
                'body' => $this->body($data['body']),
                'hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(ForumFiles::class)->bind(
                $post,
                $request->user(),
                $topic->category_id,
                $data['body'],
                $data['attachments'] ?? [],
            );
            Topic::query()
                ->where('id', $id)
                ->update(['updated_at' => now()]);
            if ($request->has('follow')) {
                $this->notifications->follow($id, $request->user()->id, $request->boolean('follow'), $post);
            }
            $this->notifications->enqueue($id, $post, $request->user()->id);
            app(ForumMentions::class)->enqueue($post, $request->user()->id, $data['body']);
            app(ForumAnnouncements::class)->enqueue($post, 'reply');
            app(ForumDrafts::class)->forget($request->user()->id, 'topic_'.$id);
            app(PluginEvents::class)->publish(
                'forum.post.published',
                [
                    'topic_id' => $id,
                    'post_id' => $post,
                    'category_id' => (int) $topic->category_id,
                    'user_id' => (int) $request->user()->id,
                    'is_topic_start' => false,
                ],
                'forum.post.published:'.$post,
            );

            return $post;
        });

        return redirect(TopicUrl::forPost($id, $post));
    }

    public function report(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        $this->publishedPost($request, $id);
        Report::query()->updateOrInsert(
            ['post_id' => $id, 'user_id' => $request->user()->id],
            ['reason' => $data['reason'], 'resolved' => false, 'created_at' => now()],
        );

        return back()->with('status', __('forum::messages.discussions.reported'));
    }

    public function like(Request $request, int $id): RedirectResponse
    {
        $request->validate(['liked' => 'required|boolean']);
        $post = $this->publishedPost($request, $id);
        if ((int) $post->user_id === $request->user()->id) {
            throw new HttpException(422, __('forum::messages.reactions.no_self_like'));
        }
        $key = ['post_id' => $id, 'user_id' => $request->user()->id];
        $request->boolean('liked')
            ? PostLike::query()->insertOrIgnore($key + ['created_at' => now()])
            : PostLike::query()->where($key)->delete();

        return back();
    }

    public function renameTopic(Request $request, int $id): RedirectResponse
    {
        $topic = DB::transaction(function () use ($request, $id): Topic {
            $topic = $this->access->topic($request->user(), $id, lock: true);
            $category = $this->categoryRecord($request, $topic->category_id);
            if (!$this->access->canEditOwnTopic($request->user(), $category, $topic)) {
                throw new HttpException(403);
            }
            $data = $request->validate(['title' => 'required|string|min:3|max:180']);
            if ($topic->title !== $data['title']) {
                $topic->update($data);
                Audit::record('forum.topic_renamed', ['id' => $id], $request->user());
            }

            return $topic;
        }, 3);

        return redirect()->to(TopicUrl::for($topic))->with('status', __('forum::messages.common.saved'));
    }

    public function moderateTopic(Request $request, int $id, ForumModeration $moderation): RedirectResponse
    {
        if (!$this->access->canModerate($request->user())) {
            throw new HttpException(403);
        }
        $data = $request->validate([
            'pinned' => 'required_without:locked|boolean',
            'locked' => 'required_without:pinned|boolean',
        ]);
        $moderation->updateTopic($request->user(), $id, $data);

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function moderatePost(Request $request, int $id, ForumModeration $moderation): RedirectResponse
    {
        if (!$this->access->canModerate($request->user())) {
            throw new HttpException(403);
        }
        $request->validate(['hidden' => 'required|boolean']);
        $moderation->setPostHidden($request->user(), $id, $request->boolean('hidden'));

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function tags(Request $request, int $id): RedirectResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $topic = $this->access->topic($request->user(), $id, lock: true);
            $category = $this->categoryRecord($request, $topic->category_id);
            if (!$this->access->canManageTopic($request->user(), $category, $topic)) {
                throw new HttpException(403);
            }
            $data = $request->validate(['tags' => 'nullable|array|max:5', 'tags.*' => 'integer|distinct']);
            $selected = $data['tags'] ?? [];
            $assignable = $this->access->tags($request->user())->modelKeys();
            $this->validateTags($selected, $assignable);
            $current = $topic->tags()->pluck('forum__tags.id')->all();
            $tags = array_merge(array_diff($current, $assignable), $selected);
            if (count($tags) > max(5, count($current))) {
                throw ValidationException::withMessages(['tags' => __('forum::admin.tags.tags_help')]);
            }
            $topic->tags()->sync($tags);
        }, 3);

        return back();
    }

    public function follow(Request $request, int $id): RedirectResponse
    {
        $request->validate(['following' => 'required|boolean']);
        $topic = $this->access->topic($request->user(), $id);
        $last = (int) $topic->posts()->published()->max('id');
        $this->notifications->follow($id, $request->user()->id, $request->boolean('following'), $last);

        return back();
    }

    public function following(Request $request): View
    {
        $topics = TableQuery::paginate(
            $this->access
                ->topics($request->user())
                ->join(
                    'forum__subscriptions',
                    fn ($join) => $join
                        ->on('forum__subscriptions.topic_id', '=', 'forum__topics.id')
                        ->where('forum__subscriptions.user_id', $request->user()->id),
                )
                ->select('forum__topics.*')
                ->orderByDesc('forum__topics.updated_at'),
            [
                'title' => 'forum__topics.title',
                'views' => 'forum__topics.views',
                'updated_at' => 'forum__topics.updated_at',
            ],
            pageName: 'page',
            search: ['forum__topics.title'],
        );

        return view('forum::default.topics.following', compact('topics'));
    }

    private function validateTags(array $tags, array $assignable): void
    {
        if (array_diff($tags, $assignable) !== []) {
            throw new HttpException(422, __('forum::admin.tags.invalid_tags'));
        }
    }

    private function categoryRecord(Request $request, int $id): Category
    {
        $category = Category::query()->find($id);
        if (!($category && $this->access->canRead($request->user(), $category))) {
            abort(404);
        }

        return $category;
    }

    private function publishedPost(Request $request, int $id): Post
    {
        $post = Post::query()->published()->find($id);
        if ($post === null) {
            abort(404);
        }
        $this->access->topic($request->user(), $post->topic_id);

        return $post;
    }

    private function body(string $body): string
    {
        $clean = Html::clean($body);
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) && !preg_match('/<img\b/i', $clean)) {
            throw ValidationException::withMessages(['body' => __('forum::messages.history.empty_body')]);
        }

        return $clean;
    }

    private function topicExtras(Request $request, object $topic, $posts, bool $canReply): array
    {
        return $this->polls->display($request, $topic, $canReply) + [
            'attachments' => Attachment::query()
                ->whereIn('post_id', $posts->pluck('id'))
                ->orderBy('id')
                ->get(['id', 'post_id', 'name', 'mime', 'size'])
                ->groupBy('post_id'),
            'mentionsByPost' => Mention::query()
                ->join('users', 'users.id', '=', 'forum__mentions.user_id')
                ->whereIn('post_id', $posts->pluck('id'))
                ->where('state', '!=', 'cancelled')
                ->whereNull('users.anonymized_at')
                ->select(['post_id', 'users.id', 'users.username'])
                ->get()
                ->groupBy('post_id'),
        ];
    }
}
