<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

/** DEC-10: how much a reviewer would like to review a submission. */
enum Bid: string
{
    case Eager = 'eager';
    case Willing = 'willing';
    case Unwilling = 'unwilling';
    case Conflict = 'conflict';

    /** Weight in the distribution; 0 means the reviewer is never proposed. */
    public function weight(): int
    {
        return match ($this) {
            self::Eager => 2,
            self::Willing => 1,
            self::Unwilling, self::Conflict => 0,
        };
    }

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.bids.bid.' . $this->value;
    }
}
