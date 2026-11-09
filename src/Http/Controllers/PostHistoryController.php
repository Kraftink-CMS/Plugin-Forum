<?php

namespace KraftInk\Plugins\Forum\Http\Controllers;

use App\Kraft\Audit;
use App\Kraft\Html;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\PostRevision;
use KraftInk\Plugins\Forum\Models\Posts\Report;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\ForumFiles;
use KraftInk\Plugins\Forum\Services\Notifications\ForumMentions;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

class PostHistoryController
{
    public function __construct(private ForumAccess $access)
    {
    }

    private function post(Request $request, int $id, bool $lock = false): object
    {
        $post = Post::query()->where('id', $id)->first();
        $topicQuery = $post
            ? $this->access->topics($request->user())->where('forum__topics.id', $post->topic_id)
            : null;
        $topic = $topicQuery ? ($lock ? $topicQuery->lockForUpdate() : $topicQuery)->first() : null;
        if ($topic && $lock) {
            $post = Post::query()->where('id', $id)->where('topic_id', $topic->id)->lockForUpdate()->first();
        }
        if (!($post && $topic && (!$post->hidden || $this->access->canModerate($request->user())))) {
            abort(404);
        }
        $category = Category::query()->find($topic->category_id);
        /** @var Topic $topic */
        if (!$this->access->canManagePost($request->user(), $category, $topic, $post)) {
            throw new HttpException(403);
        }

        return $post;
    }

    public function index(Request $request, int $id): View
    {
        $post = $this->post($request, $id);

        return view('forum::default.posts.history', [
            'post' => $post,
            'revisions' => $post->revisions()->orderByDesc('id')->paginate(20),
        ]);
    }

    public function edit(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string|min:2|max:50000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:5120',
        ]);
        $body = Html::clean($data['body']);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) && !preg_match('/<img\b/i', $body)) {
            throw ValidationException::withMessages(['body' => __('forum::messages.history.empty_body')]);
        }
        DB::transaction(function () use ($request, $id, $body, $data) {
            $post = $this->post($request, $id, true);
            if ($post->deleted_at) {
                throw new HttpException(410);
            }
            $post->recordRevision($request->user()->id);
            Post::query()
                ->where('id', $id)
                ->update(['body' => $body, 'updated_at' => now()]);
            app(ForumFiles::class)->bind(
                $id,
                $request->user(),
                Topic::query()->where('id', $post->topic_id)->value('category_id'),
                $body,
                $data['attachments'] ?? [],
            );
            app(ForumMentions::class)->enqueue($id, $request->user()->id, $body);
        }, 3);

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function restore(Request $request, int $id, int $revision): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $revision) {
            $post = $this->post($request, $id, true);
            $snapshot = PostRevision::query()->where('id', $revision)->where('post_id', $id)->lockForUpdate()->first();
            if ($snapshot === null) {
                abort(404);
            }
            if ($snapshot->anonymized || $snapshot->body === '' || $post->deleted_at) {
                throw new HttpException(410, __('forum::messages.history.revision_unavailable'));
            }
            $post->recordRevision($request->user()->id);
            Post::query()
                ->where('id', $id)
                ->update(['body' => Html::clean($snapshot->body), 'updated_at' => now()]);
            app(ForumMentions::class)->enqueue($id, $request->user()->id, $snapshot->body);
            Audit::record('forum.post_restored', ['post_id' => $id, 'revision_id' => $revision]);
        }, 3);

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id) {
            $post = $this->post($request, $id, true);
            Post::query()
                ->where('id', $post->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            Report::query()
                ->where('post_id', $id)
                ->update(['resolved' => true]);
            Audit::record('forum.post_removed', ['post_id' => $id]);
        }, 3);

        return back()->with('status', __('forum::messages.common.saved'));
    }
}
