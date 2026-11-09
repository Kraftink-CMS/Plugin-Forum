@use('App\Kraft\Html')
@use('App\Kraft\SiteDates')
@use('KraftInk\Plugins\Forum\Services\TopicUrl')
@extends('default.layouts.layout')
@section('title', __('forum::messages.history.history'))
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            <a class="small text-decoration-none"
                href="{{ TopicUrl::forPost($post->topic_id, $post->id) }}"><i
                    class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('forum::messages.discussions.back') }}</a>
            <h1 class="h2 mt-3 mb-0">{{ __('forum::messages.history.history') }}</h1>
        </header>
        <div id="forum-post-revisions" data-ajax-pagination tabindex="-1" class="vstack gap-4">
            @forelse($revisions as $revision)
                <article class="card site-panel mb-3">
                    <div class="card-header small text-body-secondary px-4 py-3">
                        {{ __('forum::messages.history.revision_date', ['date' => app(SiteDates::class)->format($revision->created_at)]) }}
                    </div>
                    <div class="card-body p-4">
                        @if ($revision->anonymized || $revision->body === '')
                            <p class="text-body-secondary">{{ __('forum::messages.history.revision_unavailable') }}</p>
                        @else
                            <div class="prose mb-3">
                                {!! Html::clean($revision->body) !!}
                            </div>
                            @if (!$post->deleted_at)
                                <form data-ajax="true" data-redirect-url="response" method="post"
                                    action="{{ route('plugins.forum.public.posts.revisions.restore', [$post->id, $revision->id]) }}">
                                    @csrf
                                    <button class="btn btn-outline-secondary">{{ __('forum::messages.history.restore') }}</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </article>
            @empty
                <div class="card card-body empty-state text-center">
                    <p class="mb-0">{{ __('forum::admin.moderation.no_history') }}</p>
                </div>
            @endforelse
            {{ $revisions->links() }}
        </div>
    </section>
@endsection
