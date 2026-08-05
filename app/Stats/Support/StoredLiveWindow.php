<?php

namespace App\Stats\Support;

use Carbon\CarbonImmutable;

/**
 * Splits a [from, through] day range into a stored part (covered by generated
 * summaries) and a live tail computed on read. Whole past days up to the
 * generation marker come from storage; days beyond it — always including today
 * — are computed live and merged. Centralises the covered/live arithmetic the
 * stats builders would otherwise each repeat.
 */
final class StoredLiveWindow
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $through,
        public readonly ?CarbonImmutable $storedUntil,
    ) {}

    /**
     * @param  CarbonImmutable|null  $coveredUntil  the last day storage may serve (see SummaryReader::coveredUntil)
     */
    public static function resolve(?CarbonImmutable $coveredUntil, CarbonImmutable $from, CarbonImmutable $through): self
    {
        $storedUntil = $coveredUntil !== null && $coveredUntil->greaterThanOrEqualTo($from)
            ? $coveredUntil->min($through)
            : null;

        return new self($from, $through, $storedUntil);
    }

    public function hasStored(): bool
    {
        return $this->storedUntil !== null;
    }

    /**
     * The first day the live tail must cover: the day after the stored part, or
     * the range start when nothing is stored.
     */
    public function liveFrom(): CarbonImmutable
    {
        return $this->storedUntil?->addDay() ?? $this->from;
    }
}
