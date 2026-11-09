@use('App\Kraft\Html')
@use('Illuminate\Support\Str')
@use('KraftInk\Plugins\Forum\Services\TopicUrl')
@extends('admin.layouts.layout')
@section('title', __('forum::admin.moderation.moderation'))
@section('description', __('forum::admin.moderation.reports_description'))
@section('content')
    @include('forum::admin.partials.assets')
    <div id="forum-admin-reports" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-header d-flex justify-content-between">
                <h2 class="card-title">{{ __('forum::messages.discussions.reports') }}</h2>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-secondary"
                        href="{{ route('plugins.forum.admin.reports.index') }}">{{ __('forum::admin.moderation.pending') }}</a><a
                        class="btn btn-sm btn-outline-secondary"
                        href="{{ route('plugins.forum.admin.reports.index', ['state' => 'resolved']) }}">{{ __('forum::admin.moderation.resolved') }}</a>
                </div>
            </div>
            <x-tables.controls :paginator="$reports" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="title">{{ __('forum::messages.discussions.subject') }}</th>
                            <th scope="col" data-table-sort="reason">{{ __('forum::messages.discussions.reason') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports as $report)
                            <tr>
                                <td>{{ $report->getAttribute('title') }}</td>
                                <td>{{ Str::limit($report->reason, 120) }}</td>
                                <td class="text-end">
                                    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal"
                                        data-bs-target="#forum-report-{{ $report->id }}">{{ __('forum::messages.discussions.view') }}</button>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="3" :message="__('forum::messages.discussions.no_reports')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $reports->links() }}
            </div>
        </section>
        @foreach ($reports as $report)
            @php
                $postAvailable = !$report->getAttribute('post_deleted_at') && !$report->getAttribute('topic_deleted_at');
            @endphp
            <x-forms.modal :id="'forum-report-' . $report->id" :title="$report->getAttribute('title')" :action="route('plugins.forum.admin.reports.resolve', $report->id)" :submit="__('forum::messages.discussions.dismiss')">
                <p>{{ $report->reason }}</p>
                <div class="prose border p-3">
                    {!! Html::clean($report->getAttribute('body')) !!}
                </div>
                @if ($postAvailable)
                    <a class="d-inline-block mt-3"
                        href="{{ TopicUrl::forPost((int) $report->getAttribute('topic_id'), $report->post_id) }}"
                        target="_blank">{{ __('forum::messages.discussions.view') }}</a>
                @endif
                <x-slot:footer>
                    @if ($postAvailable)
                        <button class="btn btn-outline-danger" type="submit"
                            form="forum-hide-post-{{ $report->id }}">{{ __('forum::messages.discussions.hide') }}</button>
                    @endif
                </x-slot:footer>
            </x-forms.modal>
            @if ($postAvailable)
                <form data-ajax="true" data-redirect-url="response" id="forum-hide-post-{{ $report->id }}" method="post"
                    action="{{ route('plugins.forum.admin.posts.update', $report->post_id) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="hidden" value="1">
                </form>
            @endif
        @endforeach
    </div>
@endsection
