<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceReport;
use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Attendance of an edition (ATT-01, ATT-02, EVT-05): marks by a manager
 * and the import of an online meeting report with a threshold in minutes.
 * An import never takes away a presence that a manager marked by hand.
 *
 * The same holds for each session of the program: a chair (moderator)
 * or a manager marks who was present, or imports the report of that
 * session's meeting. Presence at a seminar with hours counts towards a
 * professional development document (CRT-05, PRG-07).
 */
final class AttendanceService
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const DEFAULT_THRESHOLD = 30;
    private const SETTING = 'attendanceThreshold';
    private const K = 'plugins.generic.conferenceManager.attendance.';

    public function __construct(
        private readonly AttendanceRepository $attendance,
        private readonly EditionRepository $editions,
        private readonly ?SettingsStore $settings = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?ProgramRepository $program = null,
    ) {
    }

    public function threshold(ContextInfo $context): int
    {
        $v = $this->settings?->get($context->id, self::SETTING);
        return is_int($v) && $v >= 1 && $v <= 1440 ? $v : self::DEFAULT_THRESHOLD;
    }

    /**
     * @param list<mixed> $participationIds
     * @return int|null rows changed, null when there is no such edition
     */
    public function mark(ContextInfo $context, int $editionId, array $participationIds, bool $attended, ?int $actorId, DateTimeImmutable $now): ?int
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $participationIds), static fn (int $id): bool => $id > 0)));
        $changed = $this->attendance->mark($editionId, array_slice($ids, 0, 1000), $attended, null, self::SOURCE_MANUAL, $actorId, $now);
        if ($changed > 0) {
            $this->audit?->log($context->id, $actorId, 'attendance', $editionId, $attended ? 'marked' : 'unmarked', null, ['participations' => array_slice($ids, 0, 1000)]);
        }
        return $changed;
    }

    /**
     * Reads a meeting report. With $apply the marks are saved; without it
     * the result is a preview.
     *
     * @return array{0: ?array<string, mixed>, 1: ValidationErrors}|null null when there is no such edition
     */
    public function import(ContextInfo $context, int $editionId, string $content, mixed $threshold, bool $apply, string $locale, ?int $actorId, DateTimeImmutable $now): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        [$report, $limit, $errors] = self::read($content, $threshold);
        if ($report === null || $limit === null) {
            return [null, $errors];
        }

        $byEmail = [];
        foreach ($this->attendance->ofEdition($context->id, $editionId, $locale) as $p) {
            if ($p['status'] === ParticipationStatus::Active->value) {
                $byEmail[mb_strtolower($p['email'])][] = $p;
            }
        }
        [$matched, $unmatched, $present] = self::match(
            $report,
            $byEmail,
            $limit,
            static fn (array $p): bool => $p['attended'] && $p['source'] === self::SOURCE_MANUAL,
        );

        if ($apply) {
            foreach ($matched as $m) {
                $ids = $m['participationIds'];
                if (!$m['attended']) {
                    // A presence marked by hand stays.
                    $manual = [];
                    foreach ($byEmail[$m['email']] as $p) {
                        if ($p['attended'] && $p['source'] === self::SOURCE_MANUAL) {
                            $manual[] = $p['participationId'];
                        }
                    }
                    $ids = array_values(array_diff($ids, $manual));
                }
                $this->attendance->mark($editionId, $ids, $m['attended'], $m['minutes'], self::SOURCE_IMPORT, $actorId, $now);
            }
            $this->settings?->set($context->id, self::SETTING, $limit);
            $this->audit?->log($context->id, $actorId, 'attendance', $editionId, 'imported', null, [
                'threshold' => $limit, 'rows' => $report->rows, 'matched' => count($matched), 'present' => $present, 'unmatched' => count($unmatched),
            ]);
        }

        return [[
            'applied' => $apply,
            'threshold' => $limit,
            'rows' => $report->rows,
            'skipped' => $report->skipped,
            'present' => $present,
            'matched' => $matched,
            'unmatched' => $unmatched,
        ], $errors];
    }

    /** @return array{0: ?AttendanceReport, 1: ?int, 2: ValidationErrors} */
    private static function read(string $content, mixed $threshold): array
    {
        $errors = new ValidationErrors();
        $limit = filter_var($threshold, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        if ($limit === false) {
            $errors->add('threshold', self::K . 'error.threshold');
        }
        if ($content === '' || strlen($content) > AttendanceReport::MAX_BYTES) {
            $errors->add('file', self::K . 'error.file');
        }
        $report = $errors->isEmpty() ? AttendanceReport::parse($content) : null;
        if ($errors->isEmpty() && $report === null) {
            $errors->add('file', self::K . 'error.format');
        }
        return [$report, $limit === false ? null : (int) $limit, $errors];
    }

    /**
     * Matches the people of a report by e-mail.
     *
     * @param array<string, list<array<string, mixed>>> $byEmail
     * @param \Closure(array<string, mixed>): bool $markedByHand
     * @return array{0: list<array<string, mixed>>, 1: list<array{email: string, minutes: int}>, 2: int}
     */
    private static function match(AttendanceReport $report, array $byEmail, int $limit, \Closure $markedByHand): array
    {
        $matched = $unmatched = [];
        $present = 0;
        foreach ($report->minutes as $email => $minutes) {
            $list = $byEmail[$email] ?? [];
            if ($list === []) {
                $unmatched[] = ['email' => $email, 'minutes' => $minutes];
                continue;
            }
            $ok = $minutes >= $limit;
            $present += $ok ? 1 : 0;
            $matched[] = [
                'email' => $email,
                'name' => $list[0]['name'],
                'minutes' => $minutes,
                'attended' => $ok,
                'participationIds' => array_map(static fn (array $p): int => $p['participationId'], $list),
                'keptManual' => !$ok && array_filter($list, $markedByHand) !== [],
            ];
        }
        usort($unmatched, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);
        return [$matched, $unmatched, $present];
    }

    private function session(ContextInfo $context, int $editionId, int $sessionId): ?ProgramSession
    {
        if ($this->program === null || $this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $session = $this->program->findSession($editionId, $sessionId);
        return $session !== null && $session->type !== SessionType::Break ? $session : null;
    }

    /** A suggested threshold for a session: three quarters of its length. */
    public static function sessionThreshold(ProgramSession $session): int
    {
        return max(1, min(1440, intdiv($session->minutes() * 3, 4)));
    }

    /**
     * ATT-01 per session: the active participations of the edition with
     * their presence at the session; the presenters of its papers first.
     *
     * @return array<string, mixed>|null null when there is no such session
     */
    public function sessionSheet(ContextInfo $context, int $editionId, int $sessionId, string $locale): ?array
    {
        $session = $this->session($context, $editionId, $sessionId);
        if ($session === null || $this->program === null) {
            return null;
        }
        $marks = $this->attendance->ofSession($sessionId);
        $papers = array_map(static fn ($i): int => $i->submissionId, $session->items);
        $presenters = [];
        foreach ($this->program->presenters($papers) as $ids) {
            foreach ($ids as $id) {
                $presenters[$id] = true;
            }
        }
        $chairs = array_flip($session->chairs);
        $people = [];
        foreach ($this->attendance->ofEdition($context->id, $editionId, $locale) as $p) {
            if ($p['status'] !== ParticipationStatus::Active->value) {
                continue;
            }
            $mark = $marks[$p['participationId']] ?? null;
            $people[] = [
                'participationId' => $p['participationId'],
                'participantId' => $p['participantId'],
                'name' => $p['name'] !== '' ? $p['name'] : $p['email'],
                'email' => $p['email'],
                'affiliation' => $p['affiliation'],
                'role' => $p['role'],
                'form' => $p['form'],
                'ofSession' => isset($presenters[$p['participantId']]) || isset($chairs[$p['participantId']]),
                'present' => $mark !== null,
                'minutes' => $mark['minutes'] ?? null,
                'source' => $mark['source'] ?? null,
            ];
        }
        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        usort($people, static fn (array $a, array $b): int => [$b['ofSession'], 0] <=> [$a['ofSession'], 0]
            ?: ($collator === null ? strcmp($a['name'], $b['name']) : (int) $collator->compare($a['name'], $b['name'])));
        return [
            'session' => [
                'id' => $sessionId,
                'editionId' => $editionId,
                'type' => $session->type->value,
                'title' => (string) $session->text('title')->resolve($locale, $context->primaryLocale),
                'startsAt' => $session->startsAt,
                'endsAt' => $session->endsAt,
                'minutes' => $session->minutes(),
                'hours' => $session->type === SessionType::Seminar ? $session->hours : null,
                'chairs' => $session->chairs,
            ],
            'threshold' => self::sessionThreshold($session),
            'present' => count(array_filter($people, static fn (array $p): bool => $p['present'])),
            'people' => $people,
        ];
    }

    /**
     * @param list<mixed> $participationIds
     * @return int|null rows changed, null when there is no such session
     */
    public function markSession(ContextInfo $context, int $editionId, int $sessionId, array $participationIds, bool $attended, ?int $actorId, DateTimeImmutable $now): ?int
    {
        if ($this->session($context, $editionId, $sessionId) === null) {
            return null;
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $participationIds), static fn (int $id): bool => $id > 0))), 0, 1000);
        $changed = $this->attendance->markSession($editionId, $sessionId, $ids, $attended, null, self::SOURCE_MANUAL, $actorId, $now);
        if ($changed > 0) {
            $this->audit?->log($context->id, $actorId, 'session_attendance', $sessionId, $attended ? 'marked' : 'unmarked', null, ['editionId' => $editionId, 'participations' => $ids]);
        }
        return $changed;
    }

    /**
     * ATT-02 per session: the meeting report of one session. With $apply
     * the marks are saved; without it the result is a preview.
     *
     * @return array{0: ?array<string, mixed>, 1: ValidationErrors}|null null when there is no such session
     */
    public function importSession(ContextInfo $context, int $editionId, int $sessionId, string $content, mixed $threshold, bool $apply, string $locale, ?int $actorId, DateTimeImmutable $now): ?array
    {
        $session = $this->session($context, $editionId, $sessionId);
        if ($session === null) {
            return null;
        }
        [$report, $limit, $errors] = self::read($content, $threshold);
        if ($report === null || $limit === null) {
            return [null, $errors];
        }
        $marks = $this->attendance->ofSession($sessionId);
        $byEmail = [];
        foreach ($this->attendance->ofEdition($context->id, $editionId, $locale) as $p) {
            if ($p['status'] === ParticipationStatus::Active->value) {
                $byEmail[mb_strtolower($p['email'])][] = $p;
            }
        }
        [$matched, $unmatched, $present] = self::match(
            $report,
            $byEmail,
            $limit,
            static fn (array $p): bool => ($marks[$p['participationId']]['source'] ?? null) === self::SOURCE_MANUAL,
        );
        if ($apply) {
            foreach ($matched as $m) {
                // The repository keeps every mark made by hand.
                $this->attendance->markSession($editionId, $sessionId, $m['participationIds'], $m['attended'], $m['minutes'], self::SOURCE_IMPORT, $actorId, $now);
            }
            $this->audit?->log($context->id, $actorId, 'session_attendance', $sessionId, 'imported', null, [
                'editionId' => $editionId, 'threshold' => $limit, 'rows' => $report->rows, 'matched' => count($matched), 'present' => $present, 'unmatched' => count($unmatched),
            ]);
        }
        return [[
            'applied' => $apply,
            'threshold' => $limit,
            'rows' => $report->rows,
            'skipped' => $report->skipped,
            'present' => $present,
            'matched' => $matched,
            'unmatched' => $unmatched,
        ], $errors];
    }

    /**
     * Sessions these participants chair (the moderator's cabinet).
     *
     * @param list<int> $participantIds
     * @return list<array{sessionId: int, editionId: int, editionName: string, title: string, type: string, startsAt: DateTimeImmutable, timezone: string}>
     */
    public function chairedSessions(ContextInfo $context, array $participantIds, string $locale): array
    {
        $out = [];
        foreach ($this->program?->chairedBy($participantIds) ?? [] as $sessionId => $editionId) {
            $edition = $this->editions->find($context->id, $editionId);
            $session = $edition === null ? null : $this->program?->findSession($editionId, $sessionId);
            if ($edition === null || $session === null || $session->type === SessionType::Break) {
                continue;
            }
            $out[] = [
                'sessionId' => $sessionId,
                'editionId' => $editionId,
                'editionName' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
                'title' => (string) $session->text('title')->resolve($locale, $context->primaryLocale),
                'type' => $session->type->value,
                'startsAt' => $session->startsAt,
                'timezone' => $edition->timezone,
            ];
        }
        return $out;
    }

    /** @param list<int> $participantIds */
    public function chairs(int $sessionId, array $participantIds): bool
    {
        return isset(($this->program?->chairedBy($participantIds) ?? [])[$sessionId]);
    }
}
