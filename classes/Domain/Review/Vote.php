<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

/** DEC-09: the vote of a committee member on a submission. */
enum Vote: string
{
    case For = 'for';
    case Against = 'against';
    case Abstain = 'abstain';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.review.vote.' . $this->value;
    }
}
