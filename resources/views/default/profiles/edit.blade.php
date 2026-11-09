@extends('default.layouts.layout')
@section('title', __('forum::messages.profile.edit_profile'))
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            @if ($capabilities->extension('forum'))
                <a class="small text-decoration-none" href="{{ route('plugins.forum.public.home.index') }}"><i
                        class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('forum::messages.discussions.title') }}</a>
            @endif
            <h1 class="h2 mt-3 mb-0">{{ __('forum::messages.profile.edit_profile') }}</h1>
        </header>
        <form data-ajax="true" class="card card-body site-panel p-4" method="post"
            action="{{ route('plugins.forum.public.profiles.update') }}">
            @csrf
            @method('PUT')
            <div class="mb-3">
                <label class="form-label" for="forum-signature">{{ __('forum::messages.profile.signature') }}</label>
                <textarea class="form-control" id="forum-signature" name="signature" rows="4" maxlength="5000"
                    data-editor="tinymce">{{ old('signature', $profile?->signature) }}</textarea>
                <p class="form-text">{{ __('forum::messages.profile.signature_help') }}</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="forum-bio">{{ __('forum::messages.profile.bio') }}</label>
                <textarea class="form-control" id="forum-bio" name="bio" rows="3" maxlength="1000">{{ old('bio', $profile?->bio) }}</textarea>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="forum-website">{{ __('forum::messages.profile.website') }}</label>
                    <input class="form-control" id="forum-website" type="url" name="website" maxlength="500"
                        value="{{ old('website', $profile?->website) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="forum-discord">{{ __('forum::messages.profile.discord') }}</label>
                    <input class="form-control" id="forum-discord" name="discord" maxlength="80"
                        value="{{ old('discord', $profile?->discord) }}">
                </div>
            </div>
            <div class="border-top pt-3 mt-3 text-end">
                <button class="btn btn-primary">{{ __('common.actions.save') }}</button>
            </div>
        </form>
    </section>
@endsection
