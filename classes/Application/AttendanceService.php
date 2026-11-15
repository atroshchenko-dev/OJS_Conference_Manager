<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceReport;
use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Attendance of an edition (ATT-01, ATT-02, EVT-05): marks by a manager
 * and the import of an online meeting report with a threshold in minutes.
 * An import never takes away a presence that a manager marked by hand.
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
        if ($report === null || $limit === false) {
            return [null, $errors];
        }

        $byEmail = [];
        foreach ($this->attendance->ofEdition($context->id, $editionId, $locale) as $p) {
            if ($p['status'] === ParticipationStatus::Active->value) {
                $byEmail[mb_strtolower($p['email'])][] = $p;
            }
        }
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
                'keptManual' => !$ok && array_filter($list, static fn (array $p): bool => $p['attended'] && $p['source'] === self::SOURCE_MANUAL) !== [],
            ];
        }
        usort($unmatched, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

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
}
