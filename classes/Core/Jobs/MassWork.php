<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Jobs;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Plan, section 15.2: mass work waits for its window. A job that may not
 * run yet puts a copy of itself back into the OJS queue with a delay.
 */
final class MassWork
{
    /** Seconds the mass work of the journal has to wait; 0: it may run now. */
    public static function wait(int $contextId): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $at = ServiceFactory::loadPolicy()->deferUntil($contextId, $now);
        return $at === null ? 0 : max(60, $at->getTimestamp() - $now->getTimestamp());
    }

    /** Queues the job (again), after the delay in seconds. */
    public static function queue(object $job, int $delay = 0): void
    {
        $pending = dispatch($job);
        if ($delay > 0) {
            $pending->delay($delay);
        }
    }
}
