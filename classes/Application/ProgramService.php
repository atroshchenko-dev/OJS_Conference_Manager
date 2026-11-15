<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;

/**
 * PRG-01: the static program of an edition: accepted papers by section
 * (the OJS section of the submission) and, inside a section, by
 * presentation type. Public once the journal publishes it.
 */
final class ProgramService
{
    /** Order of the types on the page. */
    private const TYPE_ORDER = [PresentationType::Oral, PresentationType::Online, PresentationType::Poster, PresentationType::Absentee];

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
        private readonly EditorialDirectory $editorial,
    ) {
    }

    /** Whether visitors may see the program of the edition. */
    public static function isPublic(Edition $edition): bool
    {
        return $edition->programPublished && $edition->status !== EditionStatus::Draft;
    }

    /**
     * Accepted papers of the edition (in editing or later, not declined),
     * whatever their place in the schedule.
     *
     * @return list<array{submissionId: int, title: string, authors: string, section: string, type: string, published: bool}>|null
     */
    public function papers(ContextInfo $context, int $editionId, string $locale): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $regs = $this->registrations->registeredOfEdition($editionId);
        $summaries = $this->editorial->summaries(array_map(static fn ($r): int => $r->submissionId, $regs), $locale);
        $out = [];
        foreach ($regs as $r) {
            $s = $summaries[$r->submissionId] ?? null;
            if ($s === null || $s['contextId'] !== $context->id || $s['status'] === EditorialDirectory::STATUS_DECLINED
                || $s['stageId'] < EditorialDirectory::STAGE_EDITING) {
                continue;
            }
            $out[] = [
                'submissionId' => $r->submissionId,
                'title' => $s['title'],
                'authors' => $s['authors'],
                'section' => (string) $s['section'],
                'type' => ($r->presentationType ?? PresentationType::Oral)->value,
                'published' => $s['status'] === self::STATUS_PUBLISHED,
            ];
        }
        return $out;
    }

    /**
     * @return array{sections: list<array{title: string, groups: list<array{type: string, labelKey: string, items: list<array{submissionId: int, title: string, authors: string, published: bool}>}>}>, count: int}|null
     */
    public function program(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $papers = $this->papers($context, $editionId, $locale);
        if ($papers === null) {
            return null;
        }
        $bySection = [];
        foreach ($papers as $p) {
            $bySection[$p['section']][$p['type']][] = ['submissionId' => $p['submissionId'], 'title' => $p['title'], 'authors' => $p['authors'], 'published' => $p['published']];
        }
        $cmp = self::comparator($locale);
        uksort($bySection, static fn ($a, $b): int => $cmp((string) $a, (string) $b));
        $sections = [];
        foreach ($bySection as $title => $types) {
            $groups = [];
            foreach (self::TYPE_ORDER as $type) {
                $items = $types[$type->value] ?? [];
                if ($items === []) {
                    continue;
                }
                usort($items, static fn (array $a, array $b): int => $cmp($a['title'], $b['title']));
                $groups[] = ['type' => $type->value, 'labelKey' => self::groupKey($type), 'items' => $items];
            }
            $sections[] = ['title' => (string) $title, 'groups' => $groups];
        }
        return ['sections' => $sections, 'count' => count($papers)];
    }

    /** @return callable(string, string): int */
    public static function comparator(string $locale): callable
    {
        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        return static fn (string $a, string $b): int => $collator === null ? strcmp($a, $b) : (int) $collator->compare($a, $b);
    }

    private const STATUS_PUBLISHED = 3;

    private static function groupKey(PresentationType $type): string
    {
        return 'plugins.generic.conferenceManager.program.group.' . $type->value;
    }
}
