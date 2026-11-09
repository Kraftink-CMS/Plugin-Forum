@php
    $restorePollInput = old('_modal') === ($poll ? 'forum-poll-edit' : 'forum-poll-new');
@endphp
<div class="mb-3">
    <label class="form-label" for="poll-question-{{ $pollKey }}">{{ __('forum::messages.polls.question') }}</label>
    <input class="form-control" id="poll-question-{{ $pollKey }}" name="question"
        value="{{ $restorePollInput ? old('question') : $poll?->question }}" maxlength="200" required>
</div>
<div class="mb-3">
    <label class="form-label" for="poll-options-{{ $pollKey }}">{{ __('forum::messages.polls.options') }}</label>
    <textarea class="form-control" id="poll-options-{{ $pollKey }}" name="options" rows="5" maxlength="2000"
        required>{{ $restorePollInput ? old('options') : $pollOptions->pluck('label')->implode("\n") }}</textarea>
    <p class="form-text">{{ __('forum::messages.polls.poll_options_help') }}</p>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label"
            for="poll-choices-{{ $pollKey }}">{{ __('forum::messages.polls.allowed_choices') }}</label>
        <input class="form-control" id="poll-choices-{{ $pollKey }}" type="number" name="max_choices"
            min="1" max="10"
            value="{{ $restorePollInput ? old('max_choices') : $poll?->max_choices ?? 1 }}" required>
    </div>
    <div class="col-md-6">
        <label class="form-label"
            for="poll-close-{{ $pollKey }}">{{ __('forum::messages.polls.closes_at') }}</label>
        <x-forms.local-datetime name="closes_at" id="poll-close-{{ $pollKey }}" :value="$poll?->closes_at" />
    </div>
</div>
<div class="mb-3">
    <label class="form-label"
        for="poll-results-{{ $pollKey }}">{{ __('forum::messages.polls.poll_results') }}</label>
    <select class="form-select" id="poll-results-{{ $pollKey }}" name="results_visibility">
        @foreach (['always', 'after_vote', 'closed'] as $visibility)
            <option value="{{ $visibility }}" @selected(($restorePollInput ? old('results_visibility') : $poll?->results_visibility ?? 'always') === $visibility)>
                {{ __('forum::messages.polls.results_' . $visibility) }}</option>
        @endforeach
    </select>
</div>
@foreach (['allow_change_votes' => 'poll_changes', 'allow_withdraw_votes' => 'poll_withdrawal'] as $flag => $label)
    <input type="hidden" name="{{ $flag }}" value="0">
    <div class="form-check mb-2">
        <input class="form-check-input" id="poll-{{ $flag }}-{{ $pollKey }}" type="checkbox"
            name="{{ $flag }}" value="1" @checked($restorePollInput ? old($flag) : $poll?->$flag)>
        <label class="form-check-label"
            for="poll-{{ $flag }}-{{ $pollKey }}">{{ __('forum::messages.polls.' . $label) }}</label>
    </div>
@endforeach
@if ($poll)
    <div class="form-check mt-3">
        <input class="form-check-input" id="poll-reset-{{ $pollKey }}" type="checkbox" name="reset_votes"
            value="1" @checked($restorePollInput && old('reset_votes'))>
        <label class="form-check-label"
            for="poll-reset-{{ $pollKey }}">{{ __('forum::messages.polls.poll_reset') }}</label>
    </div>
@endif
