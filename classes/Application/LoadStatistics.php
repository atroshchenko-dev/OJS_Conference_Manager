<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use DateTimeImmutable;

/** What the load policy needs to know about the work of all journals. */
interface LoadStatistics
{
    /** Letters of mailings sent since the moment, in all journals. */
    public function mailsSentSince(DateTimeImmutable $since): int;

    /** Journals with letters of mailings still waiting. */
    public function contextsWithPendingMail(): int;

    /**
     * The nearest submission deadline (abstracts or papers) of an edition of
     * the journal in the period, or null.
     */
    public function deadlineBetween(int $contextId, DateTimeImmutable $from, DateTimeImmutable $to): ?DateTimeImmutable;
}
