<?php

namespace KraftInk\Plugins\Forum\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\ForumFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use KraftInk\Plugins\Forum\Models\Topics\Topic;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttachmentController
{
    public function upload(Request $request, int $id, ForumAccess $access, ForumFiles $files): JsonResponse
    {
        $category = Category::query()->find($id);
        if (
            !(
                $category &&
                ($access->canWrite($request->user(), $category) ||
                    ($access->canModerate($request->user()) && $access->canRead($request->user(), $category)))
            )
        ) {
            throw new HttpException(403);
        }
        $data = $request->validate(['file' => 'required|file|max:5120']);
        $attachment = $files->store($data['file'], $request->user(), $id, imageOnly: true);

        return response()->json([
            'success' => true,
            'data' => ['location' => route('plugins.forum.public.attachments.image', $attachment->id)],
        ]);
    }

    private function record(Request $request, int $id, ForumAccess $access, ForumFiles $files): object
    {
        $attachment = Attachment::query()->find($id);
        if (!($attachment && $files->safePath($attachment->path))) {
            abort(404);
        }
        if ($attachment->post_id) {
            $post = $attachment->post()->visibleTo($request->user())->first();
            if (
                !(
                    $post &&
                    $access->topics($request->user())->where('forum__topics.id', $post->topic_id)->exists()
                )
            ) {
                abort(404);
            }
        } else {
            $category = $attachment->category_id ? Category::query()->find($attachment->category_id) : null;
            if (
                !(
                    $request->user()?->id === (int) $attachment->user_id &&
                    $attachment->expires_at &&
                    now()->lt($attachment->expires_at) &&
                    $category &&
                    $access->canRead($request->user(), $category)
                )
            ) {
                abort(404);
            }
        }
        if (!Storage::disk('local')->exists($attachment->path)) {
            abort(404);
        }

        return $attachment;
    }

    public function download(Request $request, int $id, ForumAccess $access, ForumFiles $files): StreamedResponse
    {
        $attachment = $this->record($request, $id, $access, $files);

        return Storage::disk('local')->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function image(Request $request, int $id, ForumAccess $access, ForumFiles $files): StreamedResponse
    {
        $attachment = $this->record($request, $id, $access, $files);
        if (!in_array($attachment->mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            abort(404);
        }

        return Storage::disk('local')->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(Request $request, int $id, ForumAccess $access, ForumFiles $files): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $access, $files): void {
            $attachment = Attachment::query()->where('id', $id)->first();
            $post = $attachment?->post_id ? Post::query()->where('id', $attachment->post_id)->first() : null;
            if ($post === null) {
                abort(404);
            }
            $topic = $access
                ->topics($request->user())
                ->where('forum__topics.id', $post->topic_id)
                ->lockForUpdate()
                ->first();
            if ($topic === null) {
                abort(404);
            }
            $post = Post::query()
                ->where('id', $post->id)
                ->where('topic_id', $topic->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($post === null) {
                abort(404);
            }
            Attachment::query()->where('id', $id)->lockForUpdate()->first();
            $this->record($request, $id, $access, $files);
            $category = Category::query()->find($topic->category_id);
            /** @var Topic $topic */
            if (!$access->canDeleteAttachment($request->user(), $category, $topic, $post)) {
                throw new HttpException(403);
            }
            $files->deleteWhere(fn ($query) => $query->where('id', $id));
        }, 3);

        return back();
    }
}
