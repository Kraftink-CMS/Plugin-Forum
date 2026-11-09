@php
    $key = $record?->id ?? 'new';
@endphp
<div class="mb-3">
    <label class="form-label" for="tag-name-{{ $key }}">{{ __('forum::messages.discussions.name') }}</label>
    <input class="form-control" id="tag-name-{{ $key }}" name="name" maxlength="60"
        value="{{ old('name', $record?->name) }}" required>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-8">
        <label class="form-label" for="tag-slug-{{ $key }}">{{ __('forum::admin.tags.identifier') }}</label>
        <input class="form-control" id="tag-slug-{{ $key }}" name="slug" maxlength="80"
            value="{{ old('slug', $record?->slug) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="tag-color-{{ $key }}">{{ __('forum::admin.tags.color') }}</label>
        <input class="form-control form-control-color" id="tag-color-{{ $key }}" type="color" name="color"
            value="{{ old('color', $record?->color ?? '#34594b') }}" required>
    </div>
</div>
<fieldset>
    <legend class="fs-6">{{ __('forum::admin.tags.tag_roles') }}</legend>
    @foreach ($roles as $role)
        <div class="form-check">
            <input class="form-check-input" id="tag-role-{{ $key }}-{{ $role->id }}" name="roles[]"
                type="checkbox" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', $record ? $selectedRoles : [])))>
            <label class="form-check-label"
                for="tag-role-{{ $key }}-{{ $role->id }}">{{ $role->name }}</label>
        </div>
    @endforeach
    <p class="form-text mb-0">{{ __('forum::admin.tags.tag_roles_help') }}</p>
</fieldset>
