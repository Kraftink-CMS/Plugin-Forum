<?php

namespace KraftInk\Plugins\Forum\Http\Controllers;

use App\Kraft\Html;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Forum\Models\Draft;
use KraftInk\Plugins\Forum\Models\Posts\Attachment;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\ForumDrafts;
use Illuminate\Http\JsonResponse;

class DraftController
{
    public function __construct(private ForumDrafts $drafts)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'context' => ['required', 'regex:/^(?:category|topic)_[1-9][0-9]{0,18}$/D'],
            'title' => 'nullable|string|max:180',
            'body' => 'nullable|string|max:50000',
            'tags' => 'nullable|array|max:5',
            'tags.*' => 'integer|distinct',
            'follow' => 'boolean',
        ]);

        return DB::transaction(function () use ($request, $data) {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $category = $this->drafts->context($request->user(), $data['context']);
            if (
                !Draft::query()
                    ->where('user_id', $request->user()->id)
                    ->where('context', $data['context'])
                    ->where('expires_at', '>', now())
                    ->exists() &&
                Draft::query()->where('user_id', $request->user()->id)->where('expires_at', '>', now())->count() >= 30
            ) {
                throw ValidationException::withMessages(['body' => __('forum::messages.drafts.limit')]);
            }
            $allowedTags = app(ForumAccess::class)->tags($request->user())->pluck('id')->all();
            $tags = array_values(array_intersect(array_map('intval', $data['tags'] ?? []), $allowedTags));
            $payload = [
                'title' => $data['title'] ?? '',
                'body' => Html::clean($data['body'] ?? ''),
                'tags' => $tags,
                'follow' => $request->boolean('follow'),
            ];
            Draft::query()->updateOrCreate(
                ['user_id' => $request->user()->id, 'context' => $data['context']],
                ['payload' => $payload, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()],
            );
            Attachment::query()
                ->where('user_id', $request->user()->id)
                ->where('category_id', $category->id)
                ->whereNull('post_id')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()->addDay()]);

            return response()
                ->json([
                    'success' => true,
                    'data' => ['saved' => true, 'expires_at' => now()->addDay()->toIso8601String()],
                ])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function discard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', 'regex:/^(?:category|topic)_[1-9][0-9]{0,18}$/D'],
        ]);
        $this->drafts->forget($request->user()->id, $data['context']);

        return response()
            ->json(['success' => true, 'data' => ['removed' => true]])
            ->header('Cache-Control', 'private, no-store');
    }
}
