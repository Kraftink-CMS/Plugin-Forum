@php
    $key = $record?->id ?? 'new';
@endphp
<div class="mb-3">
    <label class="form-label" for="topic-title-{{ $key }}">{{ __('forum::messages.discussions.subject') }}</label>
    <input class="form-control" id="topic-title-{{ $key }}" name="title"
        value="{{ old('title', $record?->title) }}" required maxlength="180">
</div>
<div class="mb-3">
    <label class="form-label" for="topic-category-{{ $key }}">{{ __('forum::admin.moderation.category') }}</label>
    <select class="form-select" id="topic-category-{{ $key }}" name="category_id" data-forum-category required>
        <option value="">{{ __('forum::admin.moderation.choose_category') }}</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((int) old('category_id', $record?->category_id) === $category->id)>{{ $category->name }} ·
                {{ strtoupper($category->locale) }}</option>
        @endforeach
    </select>
</div>
@if (!$record)
    <div class="mb-3">
        <label class="form-label" for="topic-body-new">{{ __('forum::messages.discussions.message') }}</label>
        <textarea class="form-control" id="topic-body-new" name="body" data-forum-editor
            data-forum-upload-template="{{ route('plugins.forum.public.images.store', 0) }}"
            data-editor-upload-url="{{ route('plugins.forum.public.images.store', 0) }}" data-editor="tinymce" rows="8" required>{{ old('body') }}</textarea>
    </div>
    @include('forum::partials.attachments.fields', ['fileKey' => 'admin-topic'])
@else
    @foreach (['pinned', 'locked', 'hidden'] as $flag)
        <input type="hidden" name="{{ $flag }}" value="0">
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" id="topic-{{ $flag }}-{{ $key }}"
                name="{{ $flag }}" type="checkbox" value="1" @checked(old($flag, $record->$flag))>
            <label class="form-check-label"
                for="topic-{{ $flag }}-{{ $key }}">{{ __('forum::messages.discussions.' . $flag) }}</label>
        </div>
    @endforeach
@endif
