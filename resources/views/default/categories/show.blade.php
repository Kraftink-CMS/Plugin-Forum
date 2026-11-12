@extends('default.layouts.layout')
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            <a class="small text-decoration-none" href="{{ route('plugins.forum.public.home.index') }}"><i
                    class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('forum::messages.discussions.title') }}</a>
            <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mt-3">
                <h1 class="h2 mb-0">{{ $category->name }}</h1>
                @if ($canWrite)
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#forum-new-topic"><i
                            class="bi bi-plus-lg me-2" aria-hidden="true"></i>{{ __('forum::messages.discussions.new_topic') }}</button>
                @endif
            </div>
            @if ($category->description)
                <p class="text-body-secondary mt-2 mb-0">{{ $category->description }}</p>
            @endif
        </header>
        @include('forum::default.partials.toolbar')
        @if ($children->isNotEmpty())
            <div class="list-group mb-4">
                @foreach ($children as $child)
                    <a class="list-group-item list-group-item-action py-3"
                        href="{{ route('plugins.forum.public.categories.show', $child->id) }}">
                        <x-content-icon :name="$child->icon" class="me-2" />{{ $child->name }}</a>
                @endforeach
            </div>
        @endif
        @if ($tags->isNotEmpty())
            <form method="get" class="d-flex gap-2 mb-4" data-ajax-pagination-form="forum-category-topics">
                <select class="form-select" name="tag" aria-label="{{ __('forum::admin.tags.tags') }}">
                    <option value="">{{ __('forum::admin.tags.tags') }}</option>
                    @foreach ($tags as $tag)
                        <option value="{{ $tag->id }}" @selected(request('tag') == $tag->id)>{{ $tag->name }}</option>
                    @endforeach
                </select>
                <noscript>
                    <button class="btn btn-outline-secondary" type="submit">{{ __('common.actions.search') }}</button>
                </noscript>
            </form>
        @endif
        <div id="forum-category-topics" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            @include('forum::default.topics.partials.list')
        </div>
        @if ($canWrite)
            <x-forms.modal id="forum-new-topic" :multipart="true" :title="__('forum::messages.discussions.new_topic')" :action="route('plugins.forum.public.topics.store', $category->id)" :submit="__('forum::messages.discussions.publish')">
                <div class="mb-3">
                    <label class="form-label" for="forum-new-title">{{ __('forum::messages.discussions.subject') }}</label>
                    <input class="form-control" id="forum-new-title" name="title" maxlength="180"
                        value="{{ old('title', $draft['title'] ?? '') }}" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="forum-new-body">{{ __('forum::messages.discussions.message') }}</label>
                    <textarea class="form-control" id="forum-new-body"
                        data-editor-upload-url="{{ route('plugins.forum.public.images.store', $category->id) }}" name="body" rows="8"
                        data-editor="tinymce" required>{{ old('body', $draft['body'] ?? '') }}</textarea>
                </div>
                @include('forum::default.drafts.partials.status', [
                    'draftContext' => 'category_' . $category->id,
                ])
                @include('forum::partials.attachments.fields', ['fileKey' => 'new-topic'])
                @include('forum::default.topics.partials.tags', [
                    'chosenTags' => old('tags', $draft['tags'] ?? $defaultTags),
                ])
                <input type="hidden" name="follow" value="0">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="forum-new-follow" name="follow" value="1"
                        @checked(old('follow', $draft['follow'] ?? true))>
                    <label class="form-check-label" for="forum-new-follow">{{ __('forum::messages.following.follow_help') }}</label>
                </div>
            </x-forms.modal>
        @endif
    </section>
@endsection
