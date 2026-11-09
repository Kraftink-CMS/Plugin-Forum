<?php

namespace KraftInk\Plugins\Forum\Http\Controllers;

use App\Kraft\System\TableQuery;
use App\Kraft\Html;
use App\Kraft\Site\Seo;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Forum\Models\Posts\Post;
use KraftInk\Plugins\Forum\Models\Posts\PostLike;
use KraftInk\Plugins\Forum\Models\Profile;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class ProfileController
{
    public function edit(Request $request): View
    {
        return view('forum::default.profiles.edit', [
            'profile' => Profile::query()->where('user_id', $request->user()->id)->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'signature' => 'nullable|string|max:5000',
            'bio' => 'nullable|string|max:1000',
            'website' => 'nullable|url:http,https|max:500',
            'discord' => 'nullable|string|max:80',
        ]);
        if (!empty($data['website'])) {
            $url = parse_url($data['website']);
            if ($url === false || isset($url['user']) || isset($url['pass'])) {
                throw ValidationException::withMessages(['website' => __('forum::messages.profile.invalid_website')]);
            }
        }
        $data['signature'] = Html::clean(
            strip_tags($data['signature'] ?? '', '<p><br><strong><em><u><s><ul><ol><li><a>'),
        );
        $existing = Profile::query()->where('user_id', $request->user()->id)->first();
        Profile::query()->updateOrInsert(
            ['user_id' => $request->user()->id],
            $data + ['created_at' => $existing?->created_at ?? now(), 'updated_at' => now()],
        );

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function show(Request $request, int $id, ForumAccess $access): View
    {
        $member = User::members()->find($id);
        if (!($member && !$member->anonymized_at && !$member->import_locked && !$member->isBanned())) {
            abort(404);
        }
        $topics = $access->topics($request->user());
        $authoredPosts = Post::query()->published()->where('user_id', $id);
        $posts = (clone $authoredPosts)
            ->whereIn('topic_id', (clone $topics)->select('forum__topics.id'));
        $recentTopics = $access->topics($request->user(), app()->getLocale())
            ->where('forum__topics.hidden', false);
        $recentReplies = $authoredPosts
            ->whereIn('topic_id', $recentTopics->select('forum__topics.id'))
            ->whereExists(
                fn ($query) => $query
                    ->selectRaw('1')
                    ->from('forum__posts as earlier_posts')
                    ->whereColumn('earlier_posts.topic_id', 'forum__posts.topic_id')
                    ->whereColumn('earlier_posts.id', '<', 'forum__posts.id'),
            )
            ->with('topic:id,title')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'topic_id', 'body', 'created_at']);
        $profile = Profile::query()->where('user_id', $id)->first();
        $topicCount = (clone $topics)->where('forum__topics.user_id', $id)->count();
        $postCount = (clone $posts)->count();

        return view('forum::default.profiles.show', [
            'member' => $member,
            'profile' => $profile,
            'topicCount' => $topicCount,
            'postCount' => $postCount,
            'recentReplies' => $recentReplies,
            'seo' => [
                'excerpt' => $profile?->bio,
                'robots' => $access->canIndex() ? '' : Seo::NO_INDEX,
            ],
            'seoVariables' => [
                'member' => $member->username,
                'biography' => $profile?->bio,
                'topics_count' => $topicCount,
                'posts_count' => $postCount,
            ],
            'likeCount' => PostLike::query()->whereIn('post_id', (clone $posts)->select('id'))->count(),
            'topics' => TableQuery::paginate(
                (clone $topics)->where('forum__topics.user_id', $id)->orderByDesc('updated_at'),
                [
                    'title' => 'forum__topics.title',
                    'views' => 'forum__topics.views',
                    'updated_at' => 'forum__topics.updated_at',
                ],
                pageName: 'page',
                search: ['forum__topics.title'],
            ),
        ]);
    }
}
