@use('App\Kraft\LocalePolicy')
@extends('admin.layouts.layout')
@section('title', __('forum::messages.discussions.categories'))
@section('description', __('forum::admin.moderation.categories_description'))
@section('actions')
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#forum-preferences" type="button">
        {{ __('forum::admin.settings.preferences') }}</button>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#forum-category-new"
        type="button">{{ __('forum::messages.discussions.new_category') }}</button>
@endsection
@section('content')
    @include('forum::admin.partials.assets')
    @php
        $welcomePolicy = app(LocalePolicy::class);
        $welcomeTranslations = $welcomePolicy->allowsChoice();
        $welcomeLocales = $welcomeTranslations
            ? config('kraft.locales')
            : [$welcomePolicy->defaultLocale() => config('kraft.locales')[$welcomePolicy->defaultLocale()]];
        $listQuery = request()->only(['q', 'page', 'page_sort', 'page_direction', 'page_per_page']);
    @endphp
    <x-forms.modal id="forum-preferences" :redirect-url="null" :title="__('forum::admin.settings.preferences')" :action="route('plugins.forum.admin.settings.update')">
        <h3 class="h6">{{ __('forum::admin.settings.welcome_optional') }}</h3>
        @if ($welcomeTranslations)
            <p class="text-body-secondary">{{ __('forum::admin.settings.welcome_help') }}</p>
        @endif
        <div @class(['mb-3', 'accordion' => $welcomeTranslations]) id="forum-welcome-translations">
            @foreach ($welcomeLocales as $locale => $label)
                @php
                    $welcomeValue = old('welcome.' . $locale, $preferences['welcome'][$locale] ?? '') ?? '';
                    $welcomeValue = is_string($welcomeValue) ? $welcomeValue : '';
                    $welcomeContent = html_entity_decode(
                        strip_tags($welcomeValue, '<img><hr>'),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8',
                    );
                    $welcomeConfigured = trim(str_replace("\u{00A0}", ' ', $welcomeContent)) !== '';
                    $welcomeInvalid = $errors->has('welcome.' . $locale);
                @endphp
                <div @class(['accordion-item' => $welcomeTranslations]) @if ($welcomeTranslations) data-completion-group @endif>
                    @if ($welcomeTranslations)
                        <h4 class="accordion-header" id="forum-welcome-heading-{{ $locale }}">
                            <button @class(['accordion-button', 'collapsed' => !$welcomeInvalid]) type="button" data-bs-toggle="collapse"
                                data-bs-target="#forum-welcome-panel-{{ $locale }}"
                                aria-expanded="{{ $welcomeInvalid ? 'true' : 'false' }}"
                                aria-controls="forum-welcome-panel-{{ $locale }}">
                                <span class="d-flex flex-wrap align-items-center gap-2">
                                    <span>{{ $label }}</span>
                                    <span @class([
                                        'badge',
                                        'text-bg-success' => $welcomeConfigured,
                                        'text-bg-secondary' => !$welcomeConfigured,
                                    ]) data-completion-status
                                        data-filled-label="{{ __('forum::admin.settings.welcome_configured') }}"
                                        data-empty-label="{{ __('forum::admin.settings.welcome_fallback') }}"
                                        aria-live="polite">{{ __('forum::admin.settings.' . ($welcomeConfigured ? 'welcome_configured' : 'welcome_fallback')) }}</span>
                                </span>
                            </button>
                        </h4>
                        <div @class(['accordion-collapse', 'collapse', 'show' => $welcomeInvalid]) id="forum-welcome-panel-{{ $locale }}"
                            aria-labelledby="forum-welcome-heading-{{ $locale }}">
                            <div class="accordion-body">
                    @endif
                    <label class="form-label"
                        for="forum-welcome-{{ $locale }}">{{ __('forum::admin.settings.welcome_optional') }}</label>
                    <textarea @class(['form-control', 'is-invalid' => $welcomeInvalid]) id="forum-welcome-{{ $locale }}" name="welcome[{{ $locale }}]"
                        data-editor="tinymce" rows="5" maxlength="50000"
                        @if ($welcomeInvalid) aria-invalid="true" aria-describedby="forum-welcome-error-{{ $locale }}" @endif>{{ $welcomeValue }}</textarea>
                    @error('welcome.' . $locale)
                        <div class="invalid-feedback" id="forum-welcome-error-{{ $locale }}">{{ $message }}
                        </div>
                    @enderror
                    @if ($welcomeTranslations)
                </div>
        </div>
        @endif
        </div>
        @endforeach
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="forum-recent-count">{{ __('forum::admin.settings.recent_count') }}</label>
                <input class="form-control" id="forum-recent-count" name="recent_count" type="number" min="0"
                    max="50" value="{{ old('recent_count', $preferences['recent_count']) }}" required>
                <p class="form-text">{{ __('forum::admin.settings.recent_help') }}</p>
            </div>
            <div class="col-md-6">
                <label class="form-label"
                    for="forum-attachment-quota">{{ __('forum::admin.settings.attachment_quota_mb') }}</label>
                <input class="form-control" id="forum-attachment-quota" name="attachment_quota_mb" type="number"
                    min="8" max="2048"
                    value="{{ old('attachment_quota_mb', $preferences['attachment_quota_mb']) }}" required>
            </div>
        </div>
        <input type="hidden" name="noindex" value="0">
        <div class="form-check">
            <input class="form-check-input" id="forum-noindex" name="noindex" type="checkbox" value="1"
                @checked(old('noindex', $preferences['noindex']))>
            <label class="form-check-label" for="forum-noindex">{{ __('forum::admin.settings.noindex') }}</label>
        </div>
        <fieldset class="mt-4">
            <legend class="h6">{{ __('forum::admin.settings.posting_limits') }}</legend>
            <p class="form-text" id="forum-posting-limits-help">{{ __('forum::admin.settings.posting_limits_help') }}</p>
            <div class="row g-3">
                @foreach (['topic_limit' => [1, 100], 'reply_limit' => [1, 100], 'post_interval' => [0, 300]] as $field => [$min, $max])
                    <div class="col-md-4">
                        <label class="form-label" for="forum-{{ $field }}">{{ __('forum::admin.settings.' . $field) }}</label>
                        <input class="form-control" id="forum-{{ $field }}" name="{{ $field }}" type="number"
                            min="{{ $min }}" max="{{ $max }}" value="{{ old($field, $preferences[$field]) }}"
                            aria-describedby="forum-posting-limits-help" required>
                    </div>
                @endforeach
            </div>
        </fieldset>
    </x-forms.modal>
    <div id="forum-admin-categories" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$categories" class="mb-3" />
                <form class="d-flex gap-2" method="get">
                    <input class="form-control" name="q" value="{{ request('q') }}" maxlength="100"
                        placeholder="{{ __('common.actions.search') }}" aria-label="{{ __('common.actions.search') }}">
                    <noscript>
                        <button class="btn btn-outline-secondary">{{ __('common.actions.search') }}</button>
                    </noscript>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('forum::messages.discussions.name') }}</th>
                            <th>{{ __('forum::admin.categories.parent') }}</th>
                            <th scope="col" data-table-sort="locale">{{ __('forum::messages.discussions.language') }}</th>
                            <th scope="col" data-table-sort="read_access">{{ __('forum::admin.categories.read_access') }}</th>
                            <th scope="col">{{ __('forum::messages.discussions.position') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $category)
                            <tr>
                                <td><x-content-icon :name="$category->icon" class="me-2" />{{ $category->name }}
                                    @if ($category->locked)
                                        <span
                                            class="badge text-bg-secondary ms-2">{{ __('forum::messages.discussions.locked') }}</span>
                                    @endif
                                </td>
                                <td>{{ $parents->firstWhere('id', $category->parent_id)?->name ?? '—' }}</td>
                                <td>{{ strtoupper($category->locale) }}</td>
                                <td>{{ __('forum::admin.categories.access_' . $category->read_access) }}
                                    @if ($category->read_permission)
                                        <span
                                            class="badge text-bg-warning ms-2">{{ __('forum::admin.categories.legacy_access') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $categorySiblings = $siblings[$category->locale][$category->parent_id ?? ''] ?? collect([$category]);
                                        $placementAction = route('plugins.forum.admin.categories.placement.update', ['id' => $category->id] + $listQuery);
                                    @endphp
                                    <div class="btn-group" role="group" aria-label="{{ __('forum::messages.discussions.position') }}">
                                        @foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $direction => $icon)
                                            <form data-ajax="true" data-redirect-url="response" data-success-message="false"
                                                method="post" action="{{ $placementAction }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="placement" value="{{ $direction }}">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                    aria-label="{{ __('forum::admin.categories.move_' . $direction, ['name' => $category->name]) }}"
                                                    @disabled(($direction === 'up' ? $categorySiblings->first()->id : $categorySiblings->last()->id) === $category->id)>
                                                    <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                            data-bs-toggle="dropdown">{{ __('common.labels.actions') }}</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item"
                                                    href="{{ route('plugins.forum.admin.categories.index', ['edit' => $category->id]) }}">{{ __('common.actions.edit') }}</a>
                                            </li>
                                            <li><a class="dropdown-item"
                                                    href="{{ route('plugins.forum.admin.categories.index', ['move' => $category->id] + $listQuery) }}">{{ __('forum::admin.categories.change_parent') }}</a>
                                            </li>
                                            <li>
                                                <button class="dropdown-item text-danger" data-bs-toggle="modal"
                                                    data-bs-target="#forum-category-delete-{{ $category->id }}">{{ __('common.actions.delete') }}</button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="6" :message="__('forum::admin.moderation.no_categories')" :action="__('forum::messages.discussions.new_category')" modal="forum-category-new" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $categories->links() }}
            </div>
        </section>
        <x-forms.modal id="forum-category-new" :title="__('forum::messages.discussions.new_category')" :action="route('plugins.forum.admin.categories.store')">
            @include('forum::admin.categories.partials.fields', ['record' => null])
        </x-forms.modal>
        @if ($editing)
            <x-forms.modal :id="'forum-category-edit-' . $editing->id" :title="__('forum::admin.moderation.edit_category')" :action="route('plugins.forum.admin.categories.update', $editing->id)" method="PUT" :open="true">
                @include('forum::admin.categories.partials.fields', ['record' => $editing])
            </x-forms.modal>
        @endif
        @if ($moving)
            <x-forms.modal :id="'forum-category-move-' . $moving->id"
                :title="__('forum::admin.categories.change_parent')"
                :action="route('plugins.forum.admin.categories.placement.update', ['id' => $moving->id] + $listQuery)"
                method="PATCH" size="md" :open="true">
                <p class="fw-semibold">{{ $moving->name }}</p>
                <input type="hidden" name="placement" value="parent">
                <label class="form-label" for="forum-category-move-parent">{{ __('forum::admin.categories.parent') }}</label>
                <select class="form-select" id="forum-category-move-parent" name="parent_id" aria-describedby="forum-category-move-help">
                    @php($selectedParent = old('parent_id', $moving->parent_id))
                    <option value="" @selected(!$selectedParent)>{{ __('forum::admin.categories.no_parent') }}</option>
                    @foreach ($moveTargets as $parent)
                        <option value="{{ $parent->id }}" @selected((int) $selectedParent === $parent->id)>{{ $parent->name }}</option>
                    @endforeach
                </select>
                <p class="form-text mb-0" id="forum-category-move-help">{{ __('forum::admin.categories.change_parent_help') }}</p>
            </x-forms.modal>
        @endif
        @foreach ($categories as $category)
            <x-forms.modal :id="'forum-category-delete-' . $category->id" :title="__('common.actions.delete')" :action="route('plugins.forum.admin.categories.destroy', $category->id)" method="DELETE" :submit="__('common.actions.delete')"
                size="sm">
                <p>{{ __('forum::admin.moderation.confirm_delete') }}</p>
                <p>{{ $category->name }}</p>
                <div class="form-check">
                    <input class="form-check-input" id="category-confirm-{{ $category->id }}" name="confirmed"
                        type="checkbox" value="1" required>
                    <label class="form-check-label"
                        for="category-confirm-{{ $category->id }}">{{ __('forum::admin.moderation.confirm') }}</label>
                </div>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
