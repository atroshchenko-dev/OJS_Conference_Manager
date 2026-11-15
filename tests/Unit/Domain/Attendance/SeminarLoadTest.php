<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Attendance;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\SeminarLoad;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SeminarLoadTest extends TestCase
{
    private static function session(int $id, SessionType $type, ?int $hours, ?int $ects = null): ProgramSession
    {
        return new ProgramSession($id, 1, $type, new DateTimeImmutable('2027-05-20T08:00:00Z'), new DateTimeImmutable('2027-05-20T11:00:00Z'), hours: $hours, ectsX100: $ects);
    }

    public function testOnlySeminarsWithHoursCount(): void
    {
        $all = [
            self::session(1, SessionType::Seminar, 3),
            self::session(2, SessionType::Seminar, null),
            self::session(3, SessionType::Section, 4),
            self::session(4, SessionType::Seminar, 6, 25),
        ];
        $seminars = SeminarLoad::seminars($all);
        self::assertSame([1, 4], array_map(static fn (ProgramSession $s): ?int => $s->id, $seminars));
        self::assertSame(9, SeminarLoad::total($seminars));

        $load = SeminarLoad::of($seminars, [1, 3, 4]);
        self::assertSame(9, $load->hours);
        self::assertSame(10 + 25, $load->ectsX100, 'hours / 30 for a seminar without ECTS, its own ECTS otherwise');
        self::assertCount(2, $load->sessions);

        self::assertSame(0, SeminarLoad::of($seminars, [2, 3])->hours, 'a section or a seminar without hours gives none');
        self::assertSame([], SeminarLoad::seminars([self::session(5, SessionType::Plenary, 2)]));
    }
}
