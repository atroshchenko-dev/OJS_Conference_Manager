<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Site;

use APP\plugins\generic\conferenceManager\classes\Domain\Site\SchedulerHealth;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SchedulerHealthTest extends TestCase
{
    public function testStatusByTheLatestRun(): void
    {
        $now = new DateTimeImmutable('2027-03-01T12:00:00Z');
        self::assertSame(['status' => 'never', 'minutes' => null], SchedulerHealth::check(null, $now));
        self::assertSame(['status' => 'ok', 'minutes' => 14], SchedulerHealth::check(new DateTimeImmutable('2027-03-01T11:45:30Z'), $now));
        self::assertSame(['status' => 'ok', 'minutes' => 120], SchedulerHealth::check(new DateTimeImmutable('2027-03-01T10:00:00Z'), $now));
        self::assertSame(['status' => 'stale', 'minutes' => 121], SchedulerHealth::check(new DateTimeImmutable('2027-03-01T09:59:00Z'), $now));
        // A clock a little ahead of the server is not a negative age.
        self::assertSame(['status' => 'ok', 'minutes' => 0], SchedulerHealth::check(new DateTimeImmutable('2027-03-01T12:01:00Z'), $now));
    }

    public function testLatestOfTheTasksIgnoringBrokenRecords(): void
    {
        $last = SchedulerHealth::lastRun([
            'ReminderTask' => ['at' => '2027-03-01T11:00:00Z', 'ok' => true],
            'EditionWindowsTask' => ['at' => '2027-03-01T11:45:00Z', 'ok' => false],
            'RetentionTask' => ['at' => 'yesterday'],
            'Other' => 'garbage',
        ]);
        self::assertSame('2027-03-01T11:45:00+00:00', $last?->format(DATE_ATOM));
        self::assertNull(SchedulerHealth::lastRun([]));
    }
}
