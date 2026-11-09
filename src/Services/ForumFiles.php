<?php

namespace KraftInk\Plugins\Forum\Services;

use App\Kraft\Html;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\PostRevision;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ForumFiles
{
    private const array Types = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    public function store(
        UploadedFile $file,
        User $actor,
        int $category,
        ?int $post = null,
        bool $imageOnly = false,
    ): Attachment {
        $field = $imageOnly ? 'file' : 'attachments';
        $mime = $file->isValid() ? $file->getMimeType() : null;
        if (
            $mime === null ||
            !isset(self::Types[$mime]) ||
            $file->getSize() > 5242880 ||
            ($imageOnly && !str_starts_with($mime, 'image/'))
        ) {
            throw ValidationException::withMessages([
                $field => __('forum::messages.attachments.invalid_attachment'),
            ]);
        }
        $image = null;
        if (str_starts_with($mime, 'image/')) {
            $images = app(Images::class);
            $image = $images->inspect($file);
            if (
                !$image ||
                $image->width * $image->height > 8000000 ||
                !$images->canDecode($image)
            ) {
                throw ValidationException::withMessages([
                    $field => __('forum::messages.attachments.invalid_image'),
                ]);
            }
            $image = $images->prepareUpload($file, $image, field: $field);
        }

        $size = $image?->size ?? $file->getSize();
        $path = null;
        try {
            return DB::transaction(function () use (
                $file,
                $actor,
                $category,
                $post,
                $mime,
                $size,
                $image,
                $field,
                &$path,
            ) {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $hash = $image?->sha256() ?? hash_file('sha256', $file->getRealPath());
                $existing = Attachment::query()
                    ->where('user_id', $actor->id)
                    ->where('category_id', $category)
                    ->where('post_id', $post)
                    ->where('checksum', $hash)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->first();
                if ($existing && Storage::disk('local')->exists($existing->path)) {
                    return $existing;
                }
                $quota = max(8, min(2048, (int) app(Settings::class)->get('forum.attachment_quota_mb', 64))) * 1048576;
                if (
                    Attachment::query()->where('user_id', $actor->id)->sum('size') + $size > $quota ||
                    ($post && Attachment::query()->where('post_id', $post)->count() >= 5)
                ) {
                    throw ValidationException::withMessages([
                        $field => __('forum::messages.attachments.attachment_quota'),
                    ]);
                }
                $extension = $image?->extension ?? self::Types[$mime];
                $directory = 'forum/attachments/'.now()->format('Ymd');
                $path = $directory.'/'.Str::uuid().'.'.$extension;
                DB::afterRollBack(fn () => $this->scheduleDeletion($path));
                if (
                    !Storage::disk('local')->putFileAs($directory, $image?->path ?? $file, basename($path), [
                        'visibility' => 'private',
                    ])
                ) {
                    throw new RuntimeException(__('forum::messages.attachments.attachment_failed'));
                }
                $name = mb_substr(
                    preg_replace('/[\\\\\/\p{C}]/u', '_', $file->getClientOriginalName()) ?:
                    'attachment.'.$extension,
                    0,
                    180,
                );

                return Attachment::query()->create([
                    'post_id' => $post,
                    'category_id' => $category,
                    'user_id' => $actor->id,
                    'path' => $path,
                    'name' => $name,
                    'mime' => $image?->mime ?? $mime,
                    'size' => $size,
                    'checksum' => $hash,
                    'expires_at' => $post ? null : now()->addHours(2),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->scheduleDeletion($path);
            }
            throw $exception;
        }
    }

    public function bind(int $post, User $actor, int $category, string $body, array $files = []): void
    {
        if (count($files) > 5) {
            throw ValidationException::withMessages([
                'attachments' => __('forum::messages.attachments.attachment_quota'),
            ]);
        }
        foreach ($files as $file) {
            $this->store($file, $actor, $category, $post);
        }
        $document = new DOMDocument();
        $document->loadHTML(
            '<meta charset="UTF-8">'.Html::clean($body),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        $sample = route('plugins.forum.public.attachments.image', 0);
        $pattern = '~^'.str_replace('/0/', '/([0-9]+)/', preg_quote(parse_url($sample, PHP_URL_PATH), '~')).'$~D';
        $ids = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $parts = parse_url($image->getAttribute('src'));
            if (
                $parts !== false &&
                (!isset($parts['host']) || strcasecmp($parts['host'], parse_url($sample, PHP_URL_HOST)) === 0) &&
                preg_match($pattern, $parts['path'] ?? '', $match)
            ) {
                $ids[] = (int) $match[1];
            }
        }
        if (count(array_unique($ids)) > 5) {
            throw ValidationException::withMessages([
                'attachments' => __('forum::messages.attachments.attachment_quota'),
            ]);
        }
        foreach (array_unique($ids) as $id) {
            $attachment = Attachment::query()->where('id', $id)->lockForUpdate()->first();
            if (
                !$attachment ||
                ($attachment->post_id && (int) $attachment->post_id !== $post) ||
                (!$attachment->post_id &&
                    ((int) $attachment->user_id !== $actor->id ||
                        (int) $attachment->category_id !== $category ||
                        !$attachment->expires_at ||
                        now()->gte($attachment->expires_at)))
            ) {
                throw ValidationException::withMessages([
                    'attachments' => __('forum::messages.attachments.attachment_expired'),
                ]);
            }
            if (!$attachment->post_id) {
                Attachment::query()
                    ->where('id', $id)
                    ->update(['post_id' => $post, 'expires_at' => null]);
            }
        }
        if (Attachment::query()->where('post_id', $post)->count() > 5) {
            throw ValidationException::withMessages([
                'attachments' => __('forum::messages.attachments.attachment_quota'),
            ]);
        }
    }

    public function deleteWhere(callable $scope, ?Collection $removedPosts = null): void
    {
        $query = Attachment::query();
        $scope($query);
        $attachments = $query->lockForUpdate()->get(['id', 'path']);
        $this->assertDeletable($attachments, $removedPosts ?? collect());
        foreach ($attachments as $attachment) {
            /** @var Attachment $attachment */
            Attachment::query()->where('id', $attachment->id)->delete();
            $this->scheduleDeletion($attachment->path);
        }
    }

    public function assertDeletable(Collection $attachments, Collection $removedPosts): void
    {
        foreach ($attachments as $attachment) {
            $patterns = array_map(
                fn (string $value): string => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%',
                [
                    parse_url(route('plugins.forum.public.attachments.image', $attachment->id), PHP_URL_PATH),
                    parse_url(route('plugins.forum.public.attachments.download', $attachment->id), PHP_URL_PATH),
                    $attachment->path,
                ],
            );
            $reference = fn ($query) => $query
                ->whereRaw("body LIKE ? ESCAPE '!'", [$patterns[0]])
                ->orWhereRaw("body LIKE ? ESCAPE '!'", [$patterns[1]])
                ->orWhereRaw("body LIKE ? ESCAPE '!'", [$patterns[2]]);
            if (
                Post::query()->whereNotIn('id', $removedPosts)->where($reference)->exists() ||
                PostRevision::query()->whereNotIn('post_id', $removedPosts)->where($reference)->exists()
            ) {
                throw new HttpException(409, __('forum::admin.purge_shared_attachment'));
            }
        }
    }

    public function scheduleDeletion(string $path): void
    {
        if ($this->safePath($path)) {
            app(FileDeletionQueue::class)->schedule($path, 'forum/attachments/', ['forum__attachments' => 'path']);
        }
    }

    public function safePath(string $path): bool
    {
        return preg_match('~^forum/attachments/[0-9]{8}/[a-f0-9-]{36}\.(?:png|jpg|webp|pdf|txt)$~D', $path) === 1;
    }

    public function cleanup(int $limit = 200, bool $dryRun = false): int
    {
        $cutoff = now();
        $expired = Attachment::query()->whereNull('post_id')->where('expires_at', '<=', $cutoff);
        if ($dryRun) {
            return $expired->count();
        }
        $ids = $expired->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');

        return DB::transaction(function () use ($ids, $cutoff): int {
            $files = Attachment::query()
                ->whereIn('id', $ids)
                ->whereNull('post_id')
                ->where('expires_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();
            foreach ($files as $file) {
                /** @var Attachment $file */
                $this->assertDeletable(collect([$file]), collect());
                Attachment::query()->where('id', $file->id)->delete();
                $this->scheduleDeletion($file->path);
            }

            return $files->count();
        });
    }
}
