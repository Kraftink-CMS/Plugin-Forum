@use('KraftInk\Plugins\Forum\Services\TopicUrl')
@extends('admin.layouts.layout')
@section('title', __('forum::messages.discussions.topics'))
@section('description', __('forum::admin.moderation.topics_description'))
@section('actions')
    @if (auth()->user()->hasPermission('forum.manage'))
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#forum-topic-new">
            {{ __('forum::admin.moderation.add_topic') }}</button>
    @endif
@endsection
@section('content')
    <div id="forum-admin-topics" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        @include('forum::admin.partials.assets')
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$topics" class="mb-3" />
                <form class="row g-2" method="get">
                    <div class="col-md-5">
                        <input class="form-control" name="q" value="{{ request('q') }}" maxlength="100"
                            placeholder="{{ __('common.actions.search') }}" aria-label="{{ __('common.actions.search') }}">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="category" aria-label="{{ __('forum::admin.moderation.category') }}">
                            <option value="">{{ __('forum::admin.moderation.all_categories') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="state" aria-label="{{ __('common.labels.status') }}">
                            <option value="">{{ __('forum::admin.moderation.all_states') }}</option>
                            @foreach (['pinned', 'locked', 'hidden'] as $state)
                                <option value="{{ $state }}" @selected(request('state') === $state)>
                                    {{ __('forum::messages.discussions.' . $state) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <noscript>
                        <div class="col-md-1">
                            <button class="btn btn-outline-secondary w-100" aria-label="{{ __('common.actions.search') }}"><i
                                    class="bi bi-search" aria-hidden="true"></i></button>
                        </div>
                    </noscript>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="title">{{ __('forum::messages.discussions.subject') }}</th>
                            <th scope="col" data-table-sort="category">{{ __('forum::admin.moderation.category') }}</th>
                            <th scope="col" data-table-sort="author">{{ __('forum::admin.moderation.author') }}</th>
                            <th>{{ __('common.labels.status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topics as $topic)
                            <tr>
                                <td>{{ $topic->title }}</td>
                                <td>{{ $topic->category_name }}</td>
                                <td>{{ $topic->author_username ?? __('forum::messages.discussions.deleted_user') }}</td>
                                <td>
                                    @foreach (['pinned', 'locked', 'hidden'] as $flag)
                                        @if ($topic->$flag)
                                            <span class="badge text-bg-secondary">{{ __('forum::messages.discussions.' . $flag) }}</span>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                            data-bs-toggle="dropdown">{{ __('common.labels.actions') }}</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item"
                                                    href="{{ route('plugins.forum.admin.topics.index', ['edit' => $topic->id]) }}">{{ __('common.actions.edit') }}</a>
                                            </li>
                                            <li><a class="dropdown-item"
                                                    href="{{ TopicUrl::for($topic) }}"
                                                    target="_blank">{{ __('forum::messages.discussions.view') }}</a>
                                            </li>
                                            <li>
                                                <button class="dropdown-item text-danger" data-bs-toggle="modal"
                                                    data-bs-target="#forum-topic-delete-{{ $topic->id }}">{{ __('common.actions.delete') }}</button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('forum::admin.moderation.no_topics')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $topics->links() }}
            </div>
        </section>
        @if (auth()->user()->hasPermission('forum.manage'))
            <x-forms.modal id="forum-topic-new" :multipart="true" :title="__('forum::admin.moderation.add_topic')" :action="route('plugins.forum.admin.topics.store')">
                @include('forum::admin.topics.partials.fields', ['record' => null])
            </x-forms.modal>
        @endif
        @if ($editing)
            <x-forms.modal :id="'forum-topic-edit-' . $editing->id" :title="__('forum::admin.moderation.edit_topic')" :action="route('plugins.forum.admin.topics.update', $editing->id)" method="PATCH" :open="true">
                @include('forum::admin.topics.partials.fields', ['record' => $editing])
            </x-forms.modal>
        @endif
        @foreach ($topics as $topic)
            <x-forms.modal :id="'forum-topic-delete-' . $topic->id" :title="__('common.actions.delete')" :action="route('plugins.forum.admin.topics.destroy', $topic->id)" method="DELETE" :submit="__('common.actions.delete')"
                size="sm">
                <p>{{ __('forum::admin.moderation.confirm_remove') }}</p>
                <strong>{{ $topic->title }}</strong>
                <div class="form-check mt-3">
                    <input class="form-check-input" id="topic-confirm-{{ $topic->id }}" name="confirmed" type="checkbox"
                        value="1" required>
                    <label class="form-check-label"
                        for="topic-confirm-{{ $topic->id }}">{{ __('forum::admin.moderation.confirm') }}</label>
                </div>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
