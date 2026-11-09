<?php

namespace KraftInk\Plugins\Forum\Services;

use Illuminate\Http\Request;

class ForumPolls
{
    public function __construct(private ForumAccess $access)
    {
    }

    public function display(Request $request, object $topic, ?bool $canReply = null): array
    {
        $canReply ??= $this->access->canReply($request->user(), $topic->category, $topic);
        $poll = $topic->poll;
        $ballot = $poll && $request->user() ? $poll->ballots()->where('user_id', $request->user()->id)->first() : null;
        $closed = $poll && ($poll->closed || $topic->locked || ($poll->closes_at && now()->gte($poll->closes_at)));
        $visible =
            $poll &&
            ($poll->results_visibility === 'always' ||
                $closed ||
                ($poll->results_visibility === 'after_vote' && $ballot) ||
                $this->access->canModerate($request->user()));
        $options = $poll
            ? $poll
                ->options()
                ->orderBy('position')
                ->when($visible, fn ($options) => $options->withCount(['votes as votes']))
                ->get()
            : collect();

        return [
            'poll' => $poll,
            'pollOptions' => $options,
            'pollVoted' => $ballot !== null,
            'pollChoices' => $ballot ? $ballot->votes()->pluck('option_id')->all() : [],
            'pollClosed' => (bool) $closed,
            'canVotePoll' => $poll && !$closed && $canReply && (!$ballot || $poll->allow_change_votes),
            'canWithdrawPollVote' => $poll && !$closed && $canReply && $ballot && (bool) $poll->allow_withdraw_votes,
            'pollResultsVisible' => (bool) $visible,
            'pollBallots' => $visible ? $poll->ballots()->count() : null,
        ];
    }
}
