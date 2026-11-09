<fieldset>
    <legend>{{ __('forum::admin.tags.tags') }}</legend>
    @forelse($tags as $tag)
        <label class="form-check d-block mb-2">
            <input class="form-check-input" type="checkbox" name="tags[]" value="{{ $tag->id }}"
                @checked(in_array($tag->id, $chosenTags ?? []))>
            {{ $tag->name }}</label>
    @empty
        <p class="text-body-secondary">{{ __('forum::admin.tags.no_tags') }}</p>
    @endforelse
    <small>{{ __('forum::admin.tags.tags_help') }}</small>
</fieldset>
