@extends('admin.layouts.layout')
@section('title', __('forum::admin.tags.tags'))
@section('description', __('forum::admin.moderation.tags_description'))
@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#forum-tag-new">
        {{ __('forum::admin.moderation.add_tag') }}</button>
@endsection
@section('content')
    <div id="forum-admin-tags" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        @include('forum::admin.partials.assets')
        <section class="card">
            <x-tables.controls :paginator="$tags" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('forum::messages.discussions.name') }}</th>
                            <th scope="col" data-table-sort="slug">{{ __('forum::admin.tags.identifier') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tags as $tag)
                            <tr>
                                <td><span class="badge border text-bg-light"
                                        style="color:{{ $tag->color }}">{{ $tag->name }}</span></td>
                                <td>{{ $tag->slug }}</td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                            data-bs-toggle="dropdown">{{ __('common.labels.actions') }}</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item"
                                                    href="{{ route('plugins.forum.admin.tags.index', ['edit' => $tag->id]) }}">{{ __('common.actions.edit') }}</a>
                                            </li>
                                            <li>
                                                <button class="dropdown-item text-danger" data-bs-toggle="modal"
                                                    data-bs-target="#forum-tag-delete-{{ $tag->id }}">{{ __('common.actions.delete') }}</button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="3" :message="__('forum::admin.moderation.no_tags')" :action="__('forum::admin.moderation.add_tag')" modal="forum-tag-new" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $tags->links() }}
            </div>
        </section>
        <x-forms.modal id="forum-tag-new" :title="__('forum::admin.moderation.add_tag')" :action="route('plugins.forum.admin.tags.store')">
            @include('forum::admin.tags.partials.fields', ['record' => null])
        </x-forms.modal>
        @if ($editing)
            <x-forms.modal :id="'forum-tag-edit-' . $editing->id" :title="__('forum::admin.moderation.edit_tag')" :action="route('plugins.forum.admin.tags.store', $editing->id)" :open="true">
                @include('forum::admin.tags.partials.fields', ['record' => $editing])
            </x-forms.modal>
        @endif
        @foreach ($tags as $tag)
            <x-forms.modal :id="'forum-tag-delete-' . $tag->id" :title="__('common.actions.delete')" :action="route('plugins.forum.admin.tags.destroy', $tag->id)" method="DELETE" :submit="__('common.actions.delete')"
                size="sm">
                <p>{{ __('forum::admin.tags.delete_tag') }}</p>
                <div class="form-check">
                    <input class="form-check-input" id="tag-confirm-{{ $tag->id }}" name="confirmed" type="checkbox"
                        value="1" required>
                    <label class="form-check-label"
                        for="tag-confirm-{{ $tag->id }}">{{ __('forum::admin.moderation.confirm') }}</label>
                </div>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
