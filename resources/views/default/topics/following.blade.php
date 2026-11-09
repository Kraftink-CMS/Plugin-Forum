@extends('default.layouts.layout')
@section('title', __('forum::messages.following.following'))
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            @if ($capabilities->extension('forum'))
                <a class="small text-decoration-none" href="{{ route('plugins.forum.public.home.index') }}"><i
                        class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('forum::messages.discussions.title') }}</a>
            @endif
            <h1 class="h2 mt-3 mb-0">{{ __('forum::messages.following.following') }}</h1>
        </header>
        @include('forum::default.partials.toolbar')
        <div id="forum-followed-topics" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            @include('forum::default.topics.partials.list')
        </div>
    </section>
@endsection
