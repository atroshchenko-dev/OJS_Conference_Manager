<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ICalendar;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PRG-04 – PRG-08: the program by days and time, as visitors see it.
 * Times are given in UTC (for the time zone switch) and in the zone of
 * the edition. Stream links (PRG-05) are given only to registered
 * participants, and only from the time the organizer chose or, by
 * default, from the start of the day of the session.
 */
final class PublicScheduleService
{
    public const STREAM_OPEN = 'open';
    public const STREAM_LATER = 'later';
    public const STREAM_REGISTER = 'register';

    public function __construct(
        private readonly ProgramRepository $program,
        private readonly ProgramService $papers,
    ) {
    }

    /**
     * @return list<array{date: string, sessions: list<array<string, mixed>>}> empty when the program has no sessions
     */
    public function days(ContextInfo $context, Edition $edition, string $locale, bool $registered, DateTimeImmutable $now): array
    {
        $tz = $edition->timezone;
        $sessions = $this->program->sessions((int) $edition->id);
        if ($sessions === []) {
            return [];
        }
        $papers = [];
        foreach ($this->papers->papers($context, (int) $edition->id, $locale) ?? [] as $p) {
            $papers[$p['submissionId']] = $p;
        }
        $rooms = [];
        foreach ($this->program->rooms((int) $edition->id) as $r) {
            $rooms[(int) $r->id] = $r;
        }
        $names = $this->chairNames($sessions, (int) $edition->id, $locale);
        $days = [];
        foreach ($sessions as $s) {
            $room = $s->roomId === null ? null : ($rooms[$s->roomId] ?? null);
            $items = [];
            foreach ($s->itemTimes() as $submissionId => [$start, $end]) {
                $paper = $papers[$submissionId] ?? null;
                if ($paper === null) {
                    continue;
                }
                $items[] = [
                    'submissionId' => $submissionId,
                    'title' => $paper['title'],
                    'authors' => $paper['authors'],
                    'published' => $paper['published'],
                    'startsAt' => DateInput::toIsoUtc($start),
                    'start' => self::time($start, $tz),
                    'end' => self::time($end, $tz),
                ];
            }
            $text = static fn (string $f): string => (string) $s->text($f)->resolve($locale, $context->primaryLocale);
            $date = $s->startsAt->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
            $days[$date][] = [
                'id' => $s->id,
                'type' => $s->type->value,
                'labelKey' => $s->type->labelKey(),
                'title' => $text('title'),
                'description' => $text('description'),
                'learningOutcomes' => $text('learningOutcomes'),
                'startsAt' => DateInput::toIsoUtc($s->startsAt),
                'endsAt' => DateInput::toIsoUtc($s->endsAt),
                'start' => self::time($s->startsAt, $tz),
                'end' => self::time($s->endsAt, $tz),
                'room' => $room?->name,
                'chairs' => array_values(array_filter(array_map(static fn (int $id): string => $names[$id] ?? '', $s->chairs))),
                'hours' => $s->hours,
                'ectsX100' => $s->ectsX100,
                'items' => $items,
                'stream' => self::stream($s, $room?->onlineUrl, $tz, $registered, $now),
            ];
        }
        $out = [];
        foreach ($days as $date => $list) {
            $out[] = ['date' => (string) $date, 'sessions' => $list];
        }
        return $out;
    }

    /**
     * PRG-08: where and when a paper is presented.
     *
     * @return array{sessionId: int, title: string, date: string, start: string, end: string, startsAt: string, room: ?string}|null
     */
    public function ofSubmission(ContextInfo $context, Edition $edition, int $submissionId, string $locale): ?array
    {
        foreach ($this->program->sessions((int) $edition->id) as $s) {
            $times = $s->itemTimes();
            if (!isset($times[$submissionId])) {
                continue;
            }
            [$start, $end] = $times[$submissionId];
            $room = null;
            foreach ($s->roomId === null ? [] : $this->program->rooms((int) $edition->id) as $r) {
                if ($r->id === $s->roomId) {
                    $room = $r->name;
                }
            }
            return [
                'sessionId' => (int) $s->id,
                'title' => (string) $s->text('title')->resolve($locale, $context->primaryLocale),
                'date' => $start->setTimezone(new DateTimeZone($edition->timezone))->format('Y-m-d'),
                'start' => self::time($start, $edition->timezone),
                'end' => self::time($end, $edition->timezone),
                'startsAt' => (string) DateInput::toIsoUtc($start),
                'room' => $room,
            ];
        }
        return null;
    }

    /**
     * PRG-06: the program, or one session of it, as an iCalendar file.
     * Stream links are never written there: the file can be shared.
     *
     * @return string|null null when there is no such session
     */
    public function ical(ContextInfo $context, Edition $edition, string $locale, ?int $sessionId, string $programUrl, string $host, DateTimeImmutable $now): ?string
    {
        $days = $this->days($context, $edition, $locale, false, $now);
        $events = [];
        foreach ($days as $day) {
            foreach ($day['sessions'] as $s) {
                if ($sessionId !== null && $s['id'] !== $sessionId) {
                    continue;
                }
                $lines = [];
                if ($s['description'] !== '') {
                    $lines[] = $s['description'];
                }
                foreach ($s['items'] as $i) {
                    $lines[] = $i['start'] . ' ' . $i['title'] . ($i['authors'] !== '' ? ' (' . $i['authors'] . ')' : '');
                }
                $events[] = [
                    'uid' => 'session-' . $s['id'] . '-' . $edition->id . '@' . ($host !== '' ? $host : 'conference'),
                    'start' => new DateTimeImmutable((string) $s['startsAt']),
                    'end' => new DateTimeImmutable((string) $s['endsAt']),
                    'summary' => $s['title'],
                    'location' => (string) ($s['room'] ?? ''),
                    'description' => implode("\n", $lines),
                    'url' => $programUrl . '#cm-session-' . $s['id'],
                ];
            }
        }
        if ($sessionId !== null && $events === []) {
            return null;
        }
        return ICalendar::build((string) $edition->text('name')->resolve($locale, $context->primaryLocale), $events, $now);
    }

    /** @return array{state: string, url: ?string, from: ?string}|null */
    private static function stream(ProgramSession $s, ?string $roomUrl, string $tz, bool $registered, DateTimeImmutable $now): ?array
    {
        $url = $s->streamUrl ?? $roomUrl;
        if ($url === null) {
            return null;
        }
        if (!$registered) {
            return ['state' => self::STREAM_REGISTER, 'url' => null, 'from' => null];
        }
        $from = $s->streamVisibleFrom ?? $s->startsAt->setTimezone(new DateTimeZone($tz))->setTime(0, 0);
        if ($now < $from) {
            return ['state' => self::STREAM_LATER, 'url' => null, 'from' => DateInput::toIsoUtc($from)];
        }
        return ['state' => self::STREAM_OPEN, 'url' => $url, 'from' => null];
    }

    /**
     * @param list<ProgramSession> $sessions
     * @return array<int, string>
     */
    private function chairNames(array $sessions, int $editionId, string $locale): array
    {
        foreach ($sessions as $s) {
            if ($s->chairs !== []) {
                return array_column($this->program->people($editionId, $locale), 'name', 'id');
            }
        }
        return [];
    }

    private static function time(DateTimeImmutable $date, string $tz): string
    {
        return $date->setTimezone(new DateTimeZone($tz))->format('H:i');
    }
}
