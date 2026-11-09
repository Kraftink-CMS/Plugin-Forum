<div class="mb-3">
    <label class="form-label" for="forum-attachments-{{ $fileKey }}">{{ __('forum::messages.attachments.attachments') }}</label>
    <input class="form-control" id="forum-attachments-{{ $fileKey }}" type="file" name="attachments[]"
        accept=".png,.jpg,.jpeg,.webp,.pdf,.txt" multiple>
    <p class="form-text">{{ __('forum::messages.attachments.attachment_help') }}</p>
</div>
