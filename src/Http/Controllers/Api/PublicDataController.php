<?php

namespace KraftInk\Plugins\Forum\Http\Controllers\Api;

use App\Kraft\System\ApiPagination;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\TopicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use App\Kraft\LocalePolicy;
use KraftInk\Plugins\Forum\Models\Topics\Topic;

class PublicDataController
{
    private const CATEGORY_FIELDS = ['id', 'parent_id', 'name', 'description', 'locale'];

    private function locale(Request $request): string
    {
        $data = $request->validate([
            'locale' => ['nullable', Rule::in(array_keys(config('kraft.locales')))],
        ]);

        return $data['locale'] ?? app(LocalePolicy::class)->defaultLocale();
    }

    private function category(Request $request, int $id, ForumAccess $access): Category
    {
        $category = Category::query()
            ->where('locale', $this->locale($request))
            ->whereIn('id', $access->readableIds(null))
            ->find($id);
        if ($category === null) {
            abort(404);
        }

        return $category;
    }

    public function categories(Request $request, ForumAccess $access): JsonResponse
    {
        $rows = Category::query()
            ->where('locale', $this->locale($request))
            ->whereIn('id', $access->readableIds(null))
            ->orderBy('position')
            ->get(self::CATEGORY_FIELDS);

        return response()
            ->json(['success' => true, 'data' => $rows])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function showCategory(Request $request, int $id, ForumAccess $access): JsonResponse
    {
        return response()
            ->json(['success' => true, 'data' => $this->category($request, $id, $access)->only(self::CATEGORY_FIELDS)])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function topics(Request $request, ForumAccess $access, ?int $id = null): JsonResponse
    {
        $perPage = ApiPagination::perPage($request);
        $query = $this->topicsWithMetadata($request, $access);
        if ($id !== null) {
            $this->category($request, $id, $access);
            $query->where('category_id', $id);
        }
        $rows = $query->orderByDesc('updated_at')
            ->paginate($perPage)
            ->through(fn ($topic) => $this->topicData($topic));

        return response()
            ->json(['success' => true, 'data' => ApiPagination::data($rows)])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function topic(Request $request, int $id, ForumAccess $access): JsonResponse
    {
        $topic = $this->topicsWithMetadata($request, $access)->where('forum__topics.id', $id)->first();
        if ($topic === null) {
            abort(404);
        }

        /** @var Topic $topic */
        return response()
            ->json(['success' => true, 'data' => $this->topicData($topic)])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function posts(Request $request, int $id, ForumAccess $access): JsonResponse
    {
        if (!$access->topics(null, $this->locale($request))->where('forum__topics.id', $id)->exists()) {
            abort(404);
        }
        $perPage = ApiPagination::perPage($request);
        $rows = Post::query()
            ->published()
            ->leftJoin('users', 'users.id', '=', 'forum__posts.user_id')
            ->where('topic_id', $id)
            ->select(
                'forum__posts.id',
                'forum__posts.topic_id',
                'forum__posts.body',
                'forum__posts.created_at',
                'forum__posts.updated_at',
                'users.username as author',
            )
            ->orderBy('forum__posts.id')
            ->paginate($perPage);

        return response()
            ->json(['success' => true, 'data' => ApiPagination::data($rows)])
            ->header('Cache-Control', 'public, max-age=30');
    }

    private function topicsWithMetadata(Request $request, ForumAccess $access): Builder
    {
        return $access->topics(null, $this->locale($request))->with([
            'user:id,username',
            'tags' => fn ($tags) => $tags->assignableTo(null)->orderBy('name'),
        ]);
    }

    private function topicData(Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'category_id' => $topic->category_id,
            'title' => $topic->title,
            'author' => $topic->user?->username,
            'tags' => $topic->tags->map(fn ($tag) => $tag->only(['id', 'name', 'slug', 'color'])),
            'pinned' => (bool) $topic->pinned,
            'locked' => (bool) $topic->locked,
            'views' => (int) $topic->views,
            'url' => TopicUrl::for($topic),
            'created_at' => $topic->created_at,
            'updated_at' => $topic->updated_at,
        ];
    }

    public function feed(Request $request, string $format, ForumAccess $access, ?int $id = null): Response
    {
        if (!in_array($format, ['rss', 'atom'], true)) {
            abort(404);
        }
        $locale = $this->locale($request);
        $query = $access->topics(null, $locale);
        $title = config('app.name').' · '.trans('forum::messages.discussions.title', [], $locale);
        $url = route('plugins.forum.public.home.index');
        if ($id !== null) {
            $category = $this->category($request, $id, $access);
            $query->where('category_id', $id);
            $title .= ' · '.$category->name;
            $url = route('plugins.forum.public.categories.show', $id);
        }
        $rows = $query
            ->addSelect([
                'forum__topics.*',
                'excerpt' => Post::query()
                    ->published()
                    ->whereColumn('topic_id', 'forum__topics.id')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->select('body'),
            ])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElement($format === 'rss' ? 'rss' : 'feed');
        if ($format === 'rss') {
            $root->setAttribute('version', '2.0');
            $container = $document->createElement('channel');
            $root->appendChild($container);
        } else {
            $root->setAttribute('xmlns', 'http://www.w3.org/2005/Atom');
            $root->setAttribute('xml:lang', $locale);
            $container = $root;
        }
        $document->appendChild($root);
        $this->text($document, $container, 'title', $title);
        if ($format === 'rss') {
            $this->text($document, $container, 'link', $url);
            $this->text($document, $container, 'description', $title);
            $this->text($document, $container, 'language', $locale);
        } else {
            $this->text($document, $container, 'id', $url);
            $link = $document->createElement('link');
            $link->setAttribute('href', $url);
            $container->appendChild($link);
            $this->text(
                $document,
                $container,
                'updated',
                Carbon::parse($rows->max('updated_at') ?? '2000-01-01')->toAtomString(),
            );
            $author = $document->createElement('author');
            $this->text($document, $author, 'name', config('app.name'));
            $container->appendChild($author);
        }
        foreach ($rows as $topic) {
            /** @var Topic $topic */
            $item = $document->createElement($format === 'rss' ? 'item' : 'entry');
            $this->text($document, $item, 'title', $topic->title);
            $topicUrl = TopicUrl::for($topic);
            $this->text($document, $item, $format === 'rss' ? 'guid' : 'id', $topicUrl);
            if ($format === 'rss') {
                $this->text($document, $item, 'link', $topicUrl);
            } else {
                $link = $document->createElement('link');
                $link->setAttribute('href', $topicUrl);
                $item->appendChild($link);
            }
            $this->text(
                $document,
                $item,
                $format === 'rss' ? 'pubDate' : 'updated',
                $format === 'rss'
                    ? Carbon::parse($topic->updated_at)->toRfc2822String()
                    : Carbon::parse($topic->updated_at)->toAtomString(),
            );
            $this->text(
                $document,
                $item,
                $format === 'rss' ? 'description' : 'summary',
                Str::limit(
                    html_entity_decode(
                        strip_tags($topic->getAttribute('excerpt') ?? ''),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8',
                    ),
                    300,
                ),
            );
            $container->appendChild($item);
        }
        $xml = $document->saveXML();
        $response = response($xml)
            ->header(
                'Content-Type',
                $format === 'rss' ? 'application/rss+xml; charset=UTF-8' : 'application/atom+xml; charset=UTF-8',
            )
            ->header('Cache-Control', 'public, max-age=60')
            ->setEtag(hash('sha256', $xml));
        if ($rows->isNotEmpty()) {
            $response->setLastModified(Carbon::parse($rows->max('updated_at')));
        }
        $response->isNotModified($request);

        return $response;
    }

    public function categoryFeed(Request $request, int $id, string $format, ForumAccess $access): Response
    {
        return $this->feed($request, $format, $access, $id);
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $node = $document->createElement($name);
        $node->appendChild($document->createTextNode($value));
        $parent->appendChild($node);
    }
}
