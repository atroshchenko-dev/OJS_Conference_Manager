<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Attendance;

use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;

/**
 * PRG-07, CRT-05: the workload of a professional development document
 * taken from the program. When the program has seminars with hours, a
 * person is credited with the hours of the seminars where they were
 * present; ECTS credits come from each seminar, or hours / 30 (п. 13
 * Постанови №800) when the seminar has none. Without such seminars the
 * hours of the edition apply as before.
 */
final class SeminarLoad
{
    /** @param list<ProgramSession> $sessions seminars that were attended, by start */
    private function __construct(
        public readonly int $hours,
        public readonly int $ectsX100,
        public readonly array $sessions,
    ) {
    }

    /**
     * @param list<ProgramSession> $sessions of the edition
     * @return list<ProgramSession> seminars with hours
     */
    public static function seminars(array $sessions): array
    {
        return array_values(array_filter($sessions, static fn (ProgramSession $s): bool => $s->type === SessionType::Seminar && ($s->hours ?? 0) > 0));
    }

    /**
     * @param list<ProgramSession> $seminars from seminars()
     * @param list<int> $attendedSessionIds
     */
    public static function of(array $seminars, array $attendedSessionIds): self
    {
        $ids = array_flip($attendedSessionIds);
        $hours = $ects = 0;
        $taken = [];
        foreach ($seminars as $s) {
            if ($s->id === null || !isset($ids[$s->id])) {
                continue;
            }
            $h = (int) $s->hours;
            $hours += $h;
            $ects += $s->ectsX100 ?? (int) round($h * 100 / 30);
            $taken[] = $s;
        }
        return new self($hours, $ects, $taken);
    }

    /**
     * Total hours of all the seminars: the most a person can get.
     *
     * @param list<ProgramSession> $seminars
     */
    public static function total(array $seminars): int
    {
        return array_sum(array_map(static fn (ProgramSession $s): int => (int) $s->hours, $seminars));
    }
}
