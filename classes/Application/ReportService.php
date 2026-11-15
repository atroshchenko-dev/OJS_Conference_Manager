<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Report\ReportTable;
use APP\plugins\generic\conferenceManager\classes\Domain\Site\SchedulerHealth;
use DateTimeImmutable;

/**
 * RPT-02: reports of an edition by journal section, presentation type,
 * participant role, category, institution, country and form, with
 * attendance; CSV for a spreadsheet. RPT-03: whether the scheduler runs.
 */
final class ReportService
{
    private const K = 'plugins.generic.conferenceManager.report.';

    /** Grouped submissions. */
    public const SUBMISSION_DIMENSIONS = ['sections', 'types'];
    /** Grouped participations. */
    public const PARTICIPANT_DIMENSIONS = ['roles', 'categories', 'affiliations', 'countries', 'forms'];

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
        private readonly EditorialDirectory $editorial,
        private readonly EditionStatistics $stats,
        private readonly FeeRepository $fees,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
        /** @var (\Closure(): array<mixed>)|null the runs the tasks noted */
        private readonly ?\Closure $taskRuns = null,
    ) {
    }

    /** @return list<array{value: string, label: string}> */
    public function dimensions(string $locale): array
    {
        return array_map(fn (string $d): array => ['value' => $d, 'label' => $this->t(self::K . 'dimension.' . $d, $locale)], [...self::SUBMISSION_DIMENSIONS, ...self::PARTICIPANT_DIMENSIONS]);
    }

    /**
     * @return array{dimension: string, columns: list<array{value: string, label: string}>, rows: list<array{label: string, counts: array<string, int>}>, totals: array<string, int>, table: ReportTable}|null
     */
    public function report(ContextInfo $context, int $editionId, string $dimension, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null) {
            return null;
        }
        if (in_array($dimension, self::SUBMISSION_DIMENSIONS, true)) {
            $table = $this->submissions($context, $editionId, $dimension, $locale);
        } elseif (in_array($dimension, self::PARTICIPANT_DIMENSIONS, true)) {
            $table = $this->participants($editionId, $dimension, $locale);
        } else {
            return null;
        }
        return [
            'dimension' => $dimension,
            'columns' => array_map(fn (string $c): array => ['value' => $c, 'label' => $this->t(self::K . 'column.' . $c, $locale)], $table->columns),
            'rows' => $table->rows(),
            'totals' => $table->totals(),
            'table' => $table,
        ];
    }

    /** The report as CSV, or null when there is no such edition or dimension. */
    public function csv(ContextInfo $context, int $editionId, string $dimension, string $locale): ?string
    {
        $r = $this->report($context, $editionId, $dimension, $locale);
        if ($r === null) {
            return null;
        }
        $header = [$this->t(self::K . 'dimension.' . $dimension, $locale), ...array_map(static fn (array $c): string => $c['label'], $r['columns'])];
        return $r['table']->csv($header, $this->t(self::K . 'total', $locale));
    }

    /**
     * RPT-03: the latest run of the plugin's tasks and whether it is recent.
     *
     * @return array{status: string, minutes: ?int, lastRunAt: ?string}
     */
    public function scheduler(DateTimeImmutable $now): array
    {
        $runs = $this->taskRuns === null ? [] : ($this->taskRuns)();
        $last = SchedulerHealth::lastRun($runs);
        return SchedulerHealth::check($last, $now) + ['lastRunAt' => $last?->format('Y-m-d\TH:i:s\Z')];
    }

    private function submissions(ContextInfo $context, int $editionId, string $dimension, string $locale): ReportTable
    {
        $table = new ReportTable(['submissions', 'accepted', 'declined']);
        $regs = $this->registrations->registeredOfEdition($editionId);
        $summaries = $this->editorial->summaries(array_map(static fn ($r): int => $r->submissionId, $regs), $locale);
        foreach ($regs as $r) {
            $s = $summaries[$r->submissionId] ?? null;
            if ($s === null || $s['contextId'] !== $context->id) {
                continue;
            }
            $declined = $s['status'] === EditorialDirectory::STATUS_DECLINED;
            $accepted = !$declined && $s['stageId'] >= EditorialDirectory::STAGE_EDITING;
            $label = $dimension === 'sections'
                ? $s['section']
                : ($r->presentationType instanceof PresentationType ? $this->t($r->presentationType->labelKey(), $locale) : '');
            $table->add($label !== '' ? $label : $this->t(self::K . 'none', $locale), ['submissions' => 1, 'accepted' => $accepted, 'declined' => $declined]);
        }
        return $table;
    }

    private function participants(int $editionId, string $dimension, string $locale): ReportTable
    {
        $table = new ReportTable(['active', 'attended', 'cancelled']);
        $categories = [];
        if ($dimension === 'categories') {
            foreach ($this->fees->categories($editionId) as $c) {
                $categories[(int) $c->id] = (string) $c->name->resolve($locale, $locale);
            }
        }
        foreach ($this->stats->participationRows($editionId) as $p) {
            $label = match ($dimension) {
                'roles' => ($role = ParticipantRole::tryFrom($p['role'])) === null ? $p['role'] : $this->t($role->labelKey(), $locale),
                'categories' => $p['categoryId'] === null ? '' : ($categories[$p['categoryId']] ?? ''),
                'affiliations' => $p['affiliation'],
                'countries' => self::country($p['country'], $locale),
                default => ($form = ParticipationForm::tryFrom($p['form'])) === null ? $p['form'] : $this->t($form->labelKey(), $locale),
            };
            $active = $p['status'] === ParticipationStatus::Active->value;
            $table->add($label !== '' ? $label : $this->t(self::K . 'none', $locale), [
                'active' => $active,
                'attended' => $active && $p['attended'],
                'cancelled' => !$active,
            ]);
        }
        return $table;
    }

    private static function country(string $code, string $locale): string
    {
        if ($code === '') {
            return '';
        }
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('und-' . $code, $locale) : '';
        return $name !== '' && $name !== $code ? $name . ' (' . $code . ')' : $code;
    }

    private function t(string $key, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, [], $locale);
    }
}
