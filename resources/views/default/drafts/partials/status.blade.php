@auth
    <div class="border rounded p-3 mb-3" data-forum-draft data-draft-context="{{ $draftContext }}"
        data-draft-url="{{ route('plugins.forum.public.drafts.store') }}" data-discard-url="{{ route('plugins.forum.public.drafts.destroy') }}"
        data-saved="{{ __('forum::messages.drafts.saved') }}" data-failed="{{ __('forum::messages.drafts.failed') }}"
        data-limit="{{ __('forum::messages.drafts.limit') }}" data-discarded="{{ __('forum::messages.drafts.discarded') }}">
        <p class="small text-body-secondary mb-0">{{ __('forum::messages.drafts.hint') }}</p>
        <p class="small mb-0 mt-2" role="status" aria-live="polite" data-draft-feedback>
            {{ !empty($draft['restored']) && !session()->hasOldInput('body') ? __('forum::messages.drafts.restored') : '' }}</p>
        <details class="small mt-2" data-draft-discard @if (empty($draft['restored'])) hidden @endif>
            <summary>{{ __('forum::messages.drafts.discard') }}</summary>
            <p class="my-2">{{ __('forum::messages.drafts.discard_confirm') }}</p>
            <button class="btn btn-sm btn-outline-danger" type="button"
                data-draft-discard-button>{{ __('forum::messages.drafts.discard') }}</button>
        </details>
    </div>
    @once
        @push('scripts')
            <script type="module"
                src="{{ route('site.extensions.assets.show', ['id' => 'forum', 'asset' => 'js/drafts.js']) }}">
            </script>
        @endpush
    @endonce
@endauth
