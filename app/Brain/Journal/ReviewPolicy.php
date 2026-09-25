<?php

namespace App\Brain\Journal;

/**
 * Who may accept, reject, dispute or withdraw what (ADR 0002 §6). The writer
 * refuses violations; the projector ignores any that reach the journal anyway.
 */
final class ReviewPolicy
{
    /**
     * Performance evaluations: an attempt's recorded outcome, judges claims on
     * attempts, and misconception definitions.
     *
     * @param  JournalEntry|null  $judgedEvent  for a judges claim, the event it judges
     */
    public static function isPerformance(JournalEntry $target, ?JournalEntry $judgedEvent): bool
    {
        if ($target->kind === Kind::Attempt) {
            return true;
        }

        return match ($target->claimType()) {
            'judges' => $judgedEvent?->kind === Kind::Attempt,
            'defines' => ($target->body['value']['entity_type'] ?? null) === 'misconception',
            default => false,
        };
    }

    /** @return string|null why the review is not allowed, or null when it is */
    public static function violation(JournalEntry $review, ?JournalEntry $target, ?JournalEntry $judgedEvent): ?string
    {
        if ($target === null) {
            return 'The reviewed claim or event does not exist.';
        }

        $decision = $review->body['value']['decision'] ?? null;
        $isLearner = $review->actor->type === 'learner';
        $performance = self::isPerformance($target, $judgedEvent);

        if ($decision === 'withdraw') {
            $ownReview = $target->claimType() === 'reviews'
                && $target->actor->type === $review->actor->type
                && $target->actor->id === $review->actor->id;

            return $ownReview ? null : 'Only your own earlier review can be withdrawn.';
        }

        if ($decision === 'dispute') {
            if (! $performance) {
                return 'Only evaluations of performance can be disputed. Reject a claim about meaning or organisation instead.';
            }

            return $isLearner ? null : 'In the pilot, only the learner can dispute.';
        }

        if ($target->kind !== Kind::Claim) {
            return 'Only claims can be accepted or rejected.';
        }

        if ($isLearner) {
            return $performance
                ? 'The learner cannot accept or reject evaluations of their own performance. Dispute it instead.'
                : null;
        }

        $method = $review->body['method']['kind'] ?? '';
        if ($method === 'rule') {
            return $decision === 'accept' ? null : 'Rules can only accept.';
        }

        $reviewer = Authority::ofMethod($method);
        $claimed = Authority::ofMethod($target->body['method']['kind'] ?? '');

        return $reviewer >= $claimed ? null : 'A reviewer cannot overrule a claim of higher authority.';
    }
}
