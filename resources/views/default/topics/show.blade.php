@use('App\Kraft\SiteDates')
@use('KraftInk\Plugins\Forum\Services\TopicUrl')
@extends('default.layouts.layout')
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            <a class="small text-decoration-none"
                href="{{ route('plugins.forum.public.categories.show', $topic->category_id) }}"><i
                    class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ $category->name }}</a>
            <h1 class="h2 my-3">{{ $topic->title }}</h1>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <span class="small text-body-secondary"><i class="bi bi-eye me-1"
                        aria-hidden="true"></i>{{ __('forum::admin.moderation.views') }} : {{ $topic->views }}</span>
                <div class="d-flex flex-wrap gap-2">
                    @if ($canFollow)
                        <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                            action="{{ route('plugins.forum.public.topics.follow', $topic->id) }}">
                            @csrf
                            <input type="hidden" name="following" value="{{ $following ? 0 : 1 }}">
                            <button type="submit"
                                class="btn btn-sm btn-outline-secondary">{{ $following ? __('forum::messages.following.unfollow') : __('forum::messages.following.follow') }}</button>
                        </form>
                    @endif
                    @if ($canRenameTopic)
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                            data-bs-target="#forum-rename-topic">{{ __('forum::messages.discussions.edit_title') }}</button>
                    @endif
                    @if ($moderator)
                        <form class="d-flex flex-wrap gap-2" data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('plugins.forum.public.topics.moderation.update', $topic->id) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-sm btn-outline-secondary" name="pinned"
                                value="{{ $topic->pinned ? 0 : 1 }}">{{ __('forum::messages.discussions.' . ($topic->pinned ? 'unpin' : 'pin')) }}</button>
                            <button type="submit" class="btn btn-sm btn-outline-secondary" name="locked"
                                value="{{ $topic->locked ? 0 : 1 }}">{{ __('forum::messages.discussions.' . ($topic->locked ? 'unlock' : 'lock')) }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </header>
        @if ($canRenameTopic)
            <x-forms.modal id="forum-rename-topic" :title="__('forum::messages.discussions.edit_title')"
                :action="route('plugins.forum.public.topics.title.update', $topic->id)" method="PATCH">
                <label class="form-label" for="forum-topic-title">{{ __('forum::messages.discussions.subject') }}</label>
                <input class="form-control" id="forum-topic-title" name="title" minlength="3" maxlength="180"
                    value="{{ old('_modal') === 'forum-rename-topic' ? old('title') : $topic->title }}" required>
            </x-forms.modal>
        @endif
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach ($tags->whereIn('id', $selectedTags) as $tag)
                <span class="badge border text-bg-light" style="color:{{ $tag->color }}">{{ $tag->name }}</span>
            @endforeach
        </div>
        @if ($canManageTopic)
            <details class="card card-body site-panel mb-4">
                <summary class="small fw-medium">{{ __('forum::admin.tags.manage_tags') }}</summary>
                <form data-ajax="true" data-redirect-url="response" class="mt-3" method="post"
                    action="{{ route('plugins.forum.public.topics.tags.update', $topic->id) }}">
                    @csrf
                    @method('PUT')
                    @include('forum::default.topics.partials.tags', ['chosenTags' => $selectedTags])
                    <button
                        class="btn btn-sm btn-outline-secondary mt-2">{{ __('forum::messages.discussions.save') }}</button>
                </form>
            </details>
        @endif
        @if ($poll)
            <section class="card site-panel mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3"><i class="bi bi-bar-chart me-2 text-primary"
                            aria-hidden="true"></i>{{ $poll->question }}</h2>
                    @if ($pollResultsVisible)
                        <div class="mb-3">
                            @foreach ($pollOptions as $option)
                                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                                    <span>{{ $option->label }}</span><strong>{{ $option->votes }}</strong>
                                </div>
                            @endforeach
                            <p class="text-body-secondary small mt-2">
                                {{ __('forum::messages.polls.ballots', ['count' => $pollBallots]) }}</p>
                        </div>
                    @else
                        <p class="text-body-secondary">{{ __('forum::messages.polls.results_hidden') }}</p>
                    @endif
                    @if ($pollClosed)
                        <p class="text-body-secondary">{{ __('forum::messages.polls.poll_closed') }}</p>
                    @elseif($canVotePoll)
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('plugins.forum.public.polls.vote', $topic->id) }}">
                            @csrf
                            <p>
                                {{ __('forum::messages.polls.max_choices', ['count' => $poll->max_choices]) }}</p>
                            @foreach ($pollOptions as $option)
                                <div class="form-check mb-2">
                                    <input class="form-check-input" id="poll-option-{{ $option->id }}"
                                        type="{{ (int) $poll->max_choices === 1 ? 'radio' : 'checkbox' }}" name="options[]"
                                        value="{{ $option->id }}" @checked(in_array($option->id, $pollChoices))>
                                    <label class="form-check-label"
                                        for="poll-option-{{ $option->id }}">{{ $option->label }}</label>
                                </div>
                            @endforeach
                            <button
                                class="btn btn-primary btn-sm mt-2">{{ $pollVoted ? __('forum::messages.discussions.save') : __('forum::messages.polls.vote') }}</button>
                        </form>
                    @elseif($pollVoted)
                        <p>{{ __('forum::messages.polls.already_voted') }}</p>
                    @else
                        @guest
                            <p>
                                <a href="{{ route('login') }}">{{ __('forum::messages.polls.login_to_vote') }}</a>
                            </p>
                        @endguest
                    @endif
                    @if ($canWithdrawPollVote)
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('plugins.forum.public.polls.withdraw', $topic->id) }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <button
                                class="btn btn-sm btn-outline-secondary">{{ __('forum::messages.polls.poll_withdraw') }}</button>
                        </form>
                    @endif
                    @if ($canManageTopic)
                        <div class="d-flex gap-2 flex-wrap mt-4">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                data-bs-target="#forum-poll-edit">{{ __('forum::messages.polls.poll_edit') }}</button>
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('plugins.forum.public.polls.close', $topic->id) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="closed" value="{{ $poll->closed ? 0 : 1 }}">
                                <button
                                    class="btn btn-sm btn-outline-secondary">{{ $poll->closed ? __('forum::messages.polls.reopen_poll') : __('forum::messages.polls.close_poll') }}</button>
                            </form>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                data-bs-target="#forum-poll-delete">{{ __('forum::messages.polls.poll_delete') }}</button>
                        </div>
                    @endif
                </div>
            </section>
            @if ($canManageTopic)
                <x-forms.modal id="forum-poll-edit" :title="__('forum::messages.polls.poll_edit')" :action="route('plugins.forum.public.polls.update', $topic->id)" method="PUT">
                    @include('forum::default.topics.partials.poll', ['pollKey' => $poll->id])
                </x-forms.modal>
                <x-forms.modal id="forum-poll-delete" :title="__('forum::messages.polls.poll_delete')" :action="route('plugins.forum.public.polls.destroy', $topic->id)" method="DELETE" :submit="__('common.actions.delete')"
                    size="sm">
                    <p>{{ __('forum::messages.polls.poll_delete_help') }}</p>
                    <div class="form-check">
                        <input class="form-check-input" id="poll-delete-confirm" name="confirmed" type="checkbox"
                            value="1" required>
                        <label class="form-check-label"
                            for="poll-delete-confirm">{{ __('forum::admin.moderation.confirm') }}</label>
                    </div>
                </x-forms.modal>
            @endif
        @elseif($canCreatePoll)
            <button class="btn btn-outline-secondary mb-4" data-bs-toggle="modal"
                data-bs-target="#forum-poll-new">{{ __('forum::messages.polls.create_poll') }}</button>
            <x-forms.modal id="forum-poll-new" :title="__('forum::messages.polls.create_poll')" :action="route('plugins.forum.public.polls.store', $topic->id)">
                @include('forum::default.topics.partials.poll', ['pollKey' => 'new'])
            </x-forms.modal>
        @endif
        <div id="forum-topic-posts" data-ajax-pagination tabindex="-1" class="vstack gap-4">
            @foreach ($posts as $post)
                <article id="post-{{ $post->id }}" class="card site-panel forum-post mb-4">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 px-4 py-3">
                        <strong class="d-inline-flex align-items-center gap-2">
                            @include('partials.users.avatar', ['avatarUser' => $post->user, 'avatarSize' => 32])
                            @if ($post->user)
                                <a class="text-decoration-none"
                                    href="{{ route('plugins.forum.public.profiles.show', $post->user_id) }}">{{ $post->username }}</a>
                            @else
                                {{ __('forum::messages.discussions.deleted_user') }}
                            @endif
                        </strong>
                        <a class="small link-secondary link-underline-opacity-0 link-underline-opacity-100-hover"
                            href="{{ TopicUrl::forPost($topic->id, $post->id) }}">
                            <time datetime="{{ app(SiteDates::class)->iso($post->created_at) }}">{{ app(SiteDates::class)->format($post->created_at) }}</time>
                        </a>
                    </div>
                    <div class="card-body p-4">
                        @if ($post->hidden)
                            <p class="badge text-bg-warning">{{ __('forum::messages.discussions.hidden') }}</p>
                        @endif
                        <div class="prose">
                            {!! $post->body !!}
                        </div>
                        @if (($attachments[$post->id] ?? collect())->isNotEmpty())
                            <ul class="list-unstyled mt-3">
                                @foreach ($attachments[$post->id] as $attachment)
                                    <li class="d-flex flex-wrap align-items-center gap-2 py-1"><a
                                            href="{{ route('plugins.forum.public.attachments.download', $attachment->id) }}"><i
                                                class="bi bi-paperclip" aria-hidden="true"></i>
                                            {{ $attachment->name }}</a><small
                                            class="text-body-secondary">{{ __('forum::messages.attachments.file_size', ['size' => number_format($attachment->size / 1024, 1)]) }}</small>
                                        @auth
                                            @if ($post->canDeleteAttachment)
                                                <form data-ajax="true" data-redirect-url="response" method="post"
                                                    action="{{ route('plugins.forum.public.attachments.destroy', $attachment->id) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-link btn-sm text-danger"
                                                        aria-label="{{ __('common.actions.delete') . ' ' . $attachment->name }}">{{ __('common.actions.delete') }}</button>
                                                </form>
                                            @endif
                                        @endauth
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (($mentionsByPost[$post->id] ?? collect())->isNotEmpty())
                            <p class="small mt-3 mb-0">
                                @foreach ($mentionsByPost[$post->id] as $mention)
                                    <a class="me-2"
                                        href="{{ route('plugins.forum.public.profiles.show', $mention->id) }}">{{ '@' . $mention->username }}</a>
                                @endforeach
                            </p>
                        @endif
                        @if ($post->signature)
                            <div class="prose small text-body-secondary border-top pt-3 mt-4">
                                {!! $post->signature !!}
                            </div>
                        @endif
                        <p class="small text-body-secondary mt-4">
                            {{ __('forum::messages.reactions.likes', ['count' => $likeCounts[$post->id] ?? 0]) }}</p>
                        @auth
                            <div class="d-flex flex-wrap gap-2 border-top pt-3">
                                @if ($post->canLike)
                                    <form data-ajax="true" data-redirect-url="response" data-success-message="false"
                                        method="post" action="{{ route('plugins.forum.public.posts.like', $post->id) }}">
                                        @csrf
                                        <input type="hidden" name="liked"
                                            value="{{ in_array($post->id, $likedPosts) ? 0 : 1 }}">
                                        <button
                                            class="btn btn-sm btn-outline-secondary">{{ in_array($post->id, $likedPosts) ? __('forum::messages.reactions.unlike') : __('forum::messages.reactions.like') }}</button>
                                    </form>
                                @endif
                                @if ($post->canManage)
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                        data-bs-target="#forum-edit-post-{{ $post->id }}">{{ __('forum::messages.discussions.edit') }}</button>
                                    <a class="btn btn-sm btn-outline-secondary"
                                        href="{{ route('plugins.forum.public.posts.history', $post->id) }}">{{ __('forum::messages.history.history') }}</a>
                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                        data-bs-target="#forum-delete-post-{{ $post->id }}">{{ __('common.actions.delete') }}</button>
                                @endif
                                @if ($post->canReport)
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                        data-bs-target="#forum-report-post-{{ $post->id }}">{{ __('forum::messages.discussions.report') }}</button>
                                @endif
                                @if ($moderator)
                                    <form data-ajax="true" data-redirect-url="response" method="post"
                                        action="{{ route('plugins.forum.public.posts.visibility.update', $post->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="hidden" value="{{ $post->hidden ? 0 : 1 }}">
                                        <button
                                            class="btn btn-sm btn-outline-secondary">{{ $post->hidden ? __('forum::messages.discussions.show') : __('forum::messages.discussions.hide') }}</button>
                                    </form>
                                @endif
                            </div>
                        @endauth
                    </div>
                </article>
                @auth
                    @if ($post->canManage)
                        <x-forms.modal :id="'forum-edit-post-' . $post->id" :multipart="true" :title="__('forum::messages.discussions.edit')" :action="route('plugins.forum.public.posts.update', $post->id)" method="PUT">
                            <label class="form-label"
                                for="forum-body-{{ $post->id }}">{{ __('forum::messages.discussions.message') }}</label>
                            <textarea class="form-control" id="forum-body-{{ $post->id }}"
                                data-editor-upload-url="{{ route('plugins.forum.public.images.store', $category->id) }}" name="body"
                                rows="8" data-editor="tinymce" required>{{ old('_modal') === 'forum-edit-post-' . $post->id ? old('body') : $post->body }}</textarea>
                            @include('forum::partials.attachments.fields', [
                                'fileKey' => 'edit-post-' . $post->id,
                            ])
                        </x-forms.modal>
                        <x-forms.modal :id="'forum-delete-post-' . $post->id" :title="__('common.actions.delete')" :action="route('plugins.forum.public.posts.destroy', $post->id)" method="DELETE"
                            :submit="__('common.actions.delete')" size="sm">
                            <p>{{ __('forum::admin.moderation.confirm_remove') }}</p>
                            <div class="form-check">
                                <input class="form-check-input" id="post-confirm-{{ $post->id }}" name="confirmed"
                                    type="checkbox" value="1" required>
                                <label class="form-check-label"
                                    for="post-confirm-{{ $post->id }}">{{ __('forum::admin.moderation.confirm') }}</label>
                            </div>
                        </x-forms.modal>
                    @endif
                    <x-forms.modal :id="'forum-report-post-' . $post->id" :title="__('forum::messages.discussions.report')" :action="route('plugins.forum.public.posts.report', $post->id)" :submit="__('forum::messages.discussions.send')">
                        <label class="form-label"
                            for="forum-reason-{{ $post->id }}">{{ __('forum::messages.discussions.reason') }}</label>
                        <textarea class="form-control" id="forum-reason-{{ $post->id }}" name="reason" rows="4"
                            maxlength="2000" required>{{ old('_modal') === 'forum-report-post-' . $post->id ? old('reason') : '' }}</textarea>
                    </x-forms.modal>
                @endauth
            @endforeach
            {{ $posts->links() }}
        </div>
        @if ($canWrite)
            <section class="card site-panel mt-4">
                <div class="card-header py-3 px-4">
                    <h2 class="h5 mb-0">{{ __('forum::messages.discussions.reply') }}</h2>
                </div>
                <div class="card-body p-4">
                    <form data-ajax="true" data-redirect-url="response" method="post"
                        action="{{ route('plugins.forum.public.topics.reply', $topic->id) }}"
                        enctype="multipart/form-data">
                        @csrf
                        <label class="form-label" for="forum-reply">{{ __('forum::messages.discussions.reply') }}</label>
                        <textarea class="form-control mb-3" id="forum-reply"
                            data-editor-upload-url="{{ route('plugins.forum.public.images.store', $category->id) }}" name="body"
                            rows="8" data-editor="tinymce" required>{{ old('_modal') ? $draft['body'] ?? '' : old('body', $draft['body'] ?? '') }}</textarea>
                        @include('forum::default.drafts.partials.status', [
                            'draftContext' => 'topic_' . $topic->id,
                        ])
                        @include('forum::partials.attachments.fields', ['fileKey' => 'reply'])
                        <input type="hidden" name="follow" value="0">
                        <div class="form-check mb-3">
                            <input class="form-check-input" id="forum-reply-follow" type="checkbox" name="follow"
                                value="1" @checked(old('follow', $draft['follow'] ?? $following))>
                            <label class="form-check-label"
                                for="forum-reply-follow">{{ __('forum::messages.following.follow_help') }}</label>
                        </div>
                        <div class="border-top pt-3 mt-3 text-end">
                            <button class="btn btn-primary"><i class="bi bi-send me-2"
                                    aria-hidden="true"></i>{{ __('forum::messages.discussions.publish') }}</button>
                        </div>
                    </form>
                </div>
            </section>
        @endif
    </section>
@endsection
