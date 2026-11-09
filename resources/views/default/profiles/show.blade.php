@use('App\Kraft\SiteDates')
@use('App\Kraft\Site\Seo')
@use('KraftInk\Plugins\Forum\Services\TopicUrl')
@extends('default.layouts.layout')
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            @if ($capabilities->extension('forum'))
                <a class="small text-decoration-none" href="{{ route('plugins.forum.public.home.index') }}"><i
                        class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('forum::messages.discussions.title') }}</a>
            @endif
            <h1 class="h2 mt-3 mb-2">{{ $member->username }}</h1>
            <p class="text-body-secondary small mb-0">{{ __('forum::messages.profile.joined') }} :
                {{ app(SiteDates::class)->format($member->created_at, 'd F Y') }}</p>
        </header>
        <div class="card card-body site-panel p-4 mb-4">
            @if ($profile?->bio)
                <p>{{ $profile->bio }}</p>
            @endif
            <div class="d-flex flex-wrap gap-4 mb-3">
                <div>
                    <span class="small text-body-secondary d-block">{{ __('forum::messages.discussions.topics') }}</span>
                    <strong class="fs-4">{{ $topicCount }}</strong>
                </div>
                <div class="border-start ps-4">
                    <span class="small text-body-secondary d-block">{{ __('forum::messages.profile.posts') }}</span>
                    <strong class="fs-4">{{ $postCount }}</strong>
                </div>
                <div class="border-start ps-4">
                    <span class="small text-body-secondary d-block">{{ __('forum::messages.profile.received_likes') }}</span>
                    <strong class="fs-4">{{ $likeCount }}</strong>
                </div>
            </div>
            @if ($profile?->website)
                <p><a href="{{ $profile->website }}" rel="nofollow noopener noreferrer"
                        target="_blank">{{ __('forum::messages.profile.website') }}</a></p>
            @endif
            @if ($profile?->discord)
                <p>{{ __('forum::messages.profile.discord') }} : {{ $profile->discord }}</p>
            @endif
            @if ($profile?->signature)
                <div class="prose border-top py-3">
                    {!! $profile->signature !!}
                </div>
            @endif
        </div>
        <h2 class="h5 section-heading">{{ __('forum::messages.discussions.topics') }}</h2>
        <div id="forum-profile-topics" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            @include('forum::default.topics.partials.list')
        </div>
        <h2 class="h5 section-heading">{{ __('forum::messages.profile.recent_replies') }}</h2>
        <div class="card site-panel">
            <ul class="list-group list-group-flush">
                @forelse ($recentReplies as $reply)
                    <li class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                            <a class="fw-medium text-break"
                                href="{{ TopicUrl::forPost($reply->topic_id, $reply->id) }}">{{ $reply->topic->title }}</a>
                            <time class="small text-body-secondary"
                                datetime="{{ app(SiteDates::class)->iso($reply->created_at) }}">{{ app(SiteDates::class)->format($reply->created_at) }}</time>
                        </div>
                        @if ($excerpt = Seo::excerpt($reply->body))
                            <p class="small text-body-secondary text-break mb-0 mt-1">{{ $excerpt }}</p>
                        @endif
                    </li>
                @empty
                    <li class="list-group-item text-body-secondary text-center py-4">{{ __('forum::messages.profile.no_recent_replies') }}</li>
                @endforelse
            </ul>
        </div>
    </section>
@endsection
