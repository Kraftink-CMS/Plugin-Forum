<?php

namespace KraftInk\Plugins\Forum\Services;

use App\Models\Accounts\User;
use KraftInk\Plugins\Forum\Models\Categories\Category;
use KraftInk\Plugins\Forum\Models\Draft;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ForumDrafts
{
    public function get(?User $user, string $context): array
    {
        if (!$user) {
            return [];
        }
        try {
            $this->context($user, $context);
        } catch (HttpExceptionInterface) {
            return [];
        }
        $row = Draft::query()
            ->where('user_id', $user->id)
            ->where('context', $context)
            ->where('expires_at', '>', now())
            ->first();
        if (!$row) {
            return [];
        }
        $draft = $row->payload;
        $allowedTags = app(ForumAccess::class)->tags($user)->pluck('id')->all();
        $draft['tags'] = array_values(array_intersect($draft['tags'] ?? [], $allowedTags));

        return $draft + ['restored' => true];
    }

    public function forget(int $user, string $context): void
    {
        Draft::query()->where('user_id', $user)->where('context', $context)->delete();
    }

    public function cleanup(bool $dryRun = false): int
    {
        $cutoff = now();
        $expired = Draft::query()->where('expires_at', '<=', $cutoff);
        if ($dryRun) {
            return $expired->count();
        }
        $count = 0;
        $expired->orderBy('id')->chunkById(500, function ($drafts) use ($cutoff, &$count): void {
            $count += Draft::query()->whereIn('id', $drafts->pluck('id'))->where('expires_at', '<=', $cutoff)->delete();
        });

        return $count;
    }

    public function context(User $user, string $context): object
    {
        if (!preg_match('/^(category|topic)_([1-9][0-9]{0,18})$/D', $context, $match)) {
            throw new HttpException(422);
        }
        $access = app(ForumAccess::class);
        if ($match[1] === 'topic') {
            $topic = $access->topics($user, app()->getLocale())->where('id', (int) $match[2])->first();
            if (!$topic) {
                abort(404);
            }
            if ($topic->locked && !$access->permission($user, 'forum.bypass-lock')) {
                throw new HttpException(403);
            }
            $category = Category::query()->find($topic->category_id);
        } else {
            $category = Category::query()->where('locale', app()->getLocale())->find((int) $match[2]);
        }
        if (!$category || !$access->canWrite($user, $category)) {
            throw new HttpException(403);
        }

        return $category;
    }
}
