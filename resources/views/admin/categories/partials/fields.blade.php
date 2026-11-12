@php
    $key = $record?->id ?? 'new';
@endphp
@php
    $modal = $record ? 'forum-category-edit-' . $record->id : 'forum-category-new';
@endphp
@php
    $value = fn($name, $fallback = null) => old('_modal') === $modal ? old($name, $fallback) : $fallback;
@endphp
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label" for="category-name-{{ $key }}">{{ __('forum::messages.discussions.name') }}</label>
        <input class="form-control" id="category-name-{{ $key }}" name="name" maxlength="120"
            value="{{ $value('name', $record?->name) }}" required>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="category-locale-{{ $key }}">{{ __('forum::messages.discussions.language') }}</label>
        <select class="form-select" id="category-locale-{{ $key }}" name="locale">
            @foreach (config('kraft.locales') as $locale => $label)
                <option value="{{ $locale }}" @selected($value('locale', $record?->locale ?? app()->getLocale()) === $locale)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-12">
        <label class="form-label"
            for="category-description-{{ $key }}">{{ __('forum::messages.discussions.description') }}</label>
        <textarea class="form-control" id="category-description-{{ $key }}" name="description" maxlength="500"
            rows="2">{{ $value('description', $record?->description) }}</textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="category-parent-{{ $key }}">{{ __('forum::admin.categories.parent') }}</label>
        <select class="form-select" id="category-parent-{{ $key }}" name="parent_id">
            <option value="">{{ __('forum::admin.categories.no_parent') }}</option>
            @foreach ($parents->where('id', '!=', $record?->id) as $parent)
                <option value="{{ $parent->id }}" @selected((int) $value('parent_id', $record?->parent_id) === $parent->id)>{{ $parent->name }} ·
                    {{ strtoupper($parent->locale) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label"
            for="category-position-{{ $key }}">{{ __('forum::messages.discussions.position') }}</label>
        <input class="form-control" id="category-position-{{ $key }}" type="number" name="position"
            min="0" max="10000" value="{{ $value('position', $record?->position ?? 0) }}" required>
    </div>
    <div class="col-md-3">
        @php
            $icon = $value('icon', $record?->icon ?? 'chat-left-text');
        @endphp
        @if (class_exists(\App\Kraft\ContentIcons::class))
            <x-dynamic-component component="admin::icon-picker" :id="'category-icon-' . $key" name="icon"
                :label="__('forum::admin.categories.icon')" :value="$icon" />
        @else
            <label class="form-label" for="category-icon-{{ $key }}">{{ __('forum::admin.categories.icon') }}</label>
            <input class="form-control" id="category-icon-{{ $key }}" name="icon" maxlength="60"
                value="{{ is_string($icon) ? $icon : '' }}">
        @endif
    </div>
    @foreach (['read' => ['public', 'members', 'roles'], 'write' => ['members', 'roles', 'none']] as $ability => $choices)
        <fieldset class="col-md-6">
            <legend class="fs-6">{{ __('forum::admin.categories.' . $ability . '_access') }}</legend>
            <select class="form-select mb-3" name="{{ $ability }}_access"
                aria-label="{{ __('forum::admin.categories.' . $ability . '_access') }}">
                @foreach ($choices as $choice)
                    <option value="{{ $choice }}" @selected($value($ability.'_access', $record?->{$ability.'_access'} ?? ($ability === 'read' ? 'public' : 'members')) === $choice)>
                        {{ __('forum::admin.categories.access_' . $choice) }}</option>
                @endforeach
            </select>
            @php
                $chosen = $value(
                    $ability . '_roles',
                    $record ? ($categoryRoles[$ability] ?? collect())->pluck('role_id')->all() : [],
                );
            @endphp
            @foreach ($roles as $role)
                <div class="form-check">
                    <input class="form-check-input"
                        id="category-role-{{ $key }}-{{ $ability }}-{{ $role->id }}"
                        name="{{ $ability }}_roles[]" type="checkbox" value="{{ $role->id }}"
                        @checked(in_array($role->id, $chosen))>
                    <label class="form-check-label"
                        for="category-role-{{ $key }}-{{ $ability }}-{{ $role->id }}">{{ $role->name }}</label>
                </div>
            @endforeach
        </fieldset>
    @endforeach
    <p class="col-12 form-text my-0">{{ __('forum::admin.categories.roles_help') }}</p>
    @if ($record && ($record->read_permission || $record->write_permission))
        <div class="col-12">
            <div class="alert alert-warning">
                {{ __('forum::admin.categories.legacy_access') }}
                <div class="form-check mt-2">
                    <input class="form-check-input" id="category-legacy-{{ $key }}" type="checkbox"
                        name="replace_legacy_access" value="1">
                    <label class="form-check-label"
                        for="category-legacy-{{ $key }}">{{ __('forum::admin.categories.replace_legacy') }}</label>
                </div>
            </div>
        </div>
    @endif
    @foreach (['locked' => 'category_locked', 'private_topics' => 'private_topics'] as $flag => $label)
        <div class="col-12">
            <input type="hidden" name="{{ $flag }}" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" id="category-{{ $flag }}-{{ $key }}" type="checkbox"
                    name="{{ $flag }}" value="1" @checked($value($flag, $record?->$flag))>
                <label class="form-check-label"
                    for="category-{{ $flag }}-{{ $key }}">{{ __('forum::admin.categories.' . $label) }}</label>
            </div>
            @if ($flag === 'private_topics')
                <p class="form-text mb-0">{{ __('forum::admin.categories.private_help') }}</p>
            @endif
        </div>
    @endforeach
    <fieldset class="col-12">
        <legend class="fs-6">{{ __('forum::admin.categories.default_tags') }}</legend>
        @foreach ($tags as $tag)
            <div class="form-check form-check-inline">
                <input class="form-check-input" id="category-default-{{ $key }}-{{ $tag->id }}"
                    type="checkbox" name="default_tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $value('default_tags', $record ? $defaultTags : [])))>
                <label class="form-check-label"
                    for="category-default-{{ $key }}-{{ $tag->id }}">{{ $tag->name }}</label>
            </div>
        @endforeach
    </fieldset>
</div>
