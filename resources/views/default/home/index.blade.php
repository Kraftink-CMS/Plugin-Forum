@extends('default.layouts.layout')
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            <h1 class="h2 mb-0">{{ __('forum::messages.discussions.title') }}</h1>
        </header>
        @if ($welcome)
            <div class="prose text-body-secondary mb-4">
                {!! $welcome !!}
            </div>
        @endif
        @include('forum::default.partials.toolbar')
        @if ($categories->isEmpty())
            <div class="card card-body empty-state text-center">
                <i class="bi bi-chat-square-text fs-2 mb-3" aria-hidden="true"></i>
                <p class="mb-0">{{ __('forum::admin.moderation.no_categories') }}</p>
            </div>
        @else
            @include('forum::default.categories.partials.tree', [
                'items' => $categories->whereNull('parent_id'),
            ])
        @endif
        @if ($latestTopics->isNotEmpty())
            <h2 class="h5 section-heading">{{ __('forum::messages.search.latest') }}</h2>
            @include('forum::default.topics.partials.list', ['topics' => $latestTopics])
        @endif
    </section>
@endsection
