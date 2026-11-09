@use('App\Kraft\Settings')
@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('forum::admin.announcements.title'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.forum.admin.categories.index') }}">
        {{ __('forum::admin.announcements.back') }}</a>
@endsection
@section('content')
    @include('forum::admin.partials.assets')
    <form data-ajax="true" data-redirect-url="response" method="post"
        action="{{ route('plugins.forum.admin.announcements.store') }}" class="card">
        @csrf
        <div class="card-body">
            <input type="hidden" name="enabled" value="0">
            <label class="form-check mb-3">
                <input class="form-check-input" name="enabled" value="1" type="checkbox" @checked(old('enabled', app(Settings::class)->get('forum.discord.enabled', false)))>
                <span class="form-check-label">{{ __('forum::admin.announcements.enabled') }}</span></label>
            <div class="mb-3">
                <label class="form-label" for="forum-webhook">{{ __('forum::admin.announcements.webhook') }}</label>
                <input class="form-control" type="password" id="forum-webhook" name="webhook_url" maxlength="255"
                    autocomplete="new-password" data-announcement-webhook>
                @if ($configured)
                    <p class="form-text">{{ __('forum::admin.announcements.configured') }}</p>
                    <label class="form-check">
                        <input class="form-check-input" name="remove_webhook" type="checkbox" value="1">
                        <span class="form-check-label">{{ __('forum::admin.announcements.remove_webhook') }}</span></label>
                @endif
            </div>
            <fieldset class="mb-3">
                <legend class="fs-6">{{ __('forum::admin.announcements.events') }}</legend>
                @foreach (['topic', 'reply'] as $event)
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="events[]" value="{{ $event }}"
                            @checked(in_array($event, old('events', app(Settings::class)->get('forum.discord.events', ['topic'])), true))>
                        <span class="form-check-label">{{ __('forum::admin.announcements.event_' . $event) }}</span></label>
                @endforeach
            </fieldset>
            <p class="form-text">{{ __('forum::admin.announcements.privacy') }}</p>
            <fieldset class="d-none" data-announcement-proof hidden disabled>
                @include('partials.forms.confirm-action', ['confirmationId' => 'forum-announcements'])
            </fieldset>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
        </div>
    </form>
    <div class="card" id="forum-admin-deliveries" data-ajax-pagination data-table tabindex="-1">
        <div class="card-header">
            <h2 class="card-title">{{ __('forum::admin.announcements.deliveries') }}</h2>
        </div>
        <x-tables.controls :paginator="$deliveries" container-class="card-body" />
        <div class="card-body p-0 table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th scope="col" data-table-sort="post_id">{{ __('forum::admin.announcements.post') }}</th>
                        <th scope="col" data-table-sort="event">{{ __('forum::admin.announcements.event') }}</th>
                        <th scope="col" data-table-sort="status">{{ __('forum::admin.announcements.state') }}</th>
                        <th scope="col" data-table-sort="created_at">{{ __('forum::admin.announcements.date') }}</th>
                        <th>{{ __('forum::admin.announcements.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deliveries as $delivery)
                        <tr>
                            <td>#{{ $delivery->post_id }}</td>
                            <td>{{ __('forum::admin.announcements.event_' . $delivery->event) }}</td>
                            <td>{{ __('forum::admin.announcements.' . $delivery->status) }}</td>
                            <td>{{ app(SiteDates::class)->format($delivery->created_at) }}</td>
                            <td>
                                @if (in_array($delivery->status, ['failed', 'uncertain', 'cancelled']))
                                    <form data-ajax="true" data-redirect-url="response" method="post"
                                        action="{{ route('plugins.forum.admin.announcements.retry', $delivery->id) }}"
                                        data-confirm="{{ __('forum::admin.announcements.retry_warning') }}">
                                        @csrf
                                        <input type="hidden" name="confirmed" value="1">
                                        <button
                                            class="btn btn-sm btn-outline-primary">{{ __('forum::admin.announcements.retry') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-tables.empty :colspan="5" :message="__('forum::admin.announcements.empty')" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $deliveries->links() }}
        </div>
    </div>
@endsection
