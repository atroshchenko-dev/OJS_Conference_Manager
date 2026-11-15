<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Book\AbstractBookHtml;
use APP\plugins\generic\conferenceManager\classes\Domain\Book\BookSettings;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * PUB-04: the book of abstracts of an edition as one PDF: the accepted
 * papers in the order of the program (sessions by time), of the journal
 * sections or of the titles, with the title page, the imprint, the
 * contents and an author index. The settings are kept per edition.
 */
final class AbstractBookService
{
    public const SETTING = 'abstractBook';
    private const K = 'plugins.generic.conferenceManager.book.';

    /** @param \Closure(string, array<string, mixed>, string): string $translate */
    public function __construct(
        private readonly EditionRepository $editions,
        private readonly ProgramService $papers,
        private readonly ProgramRepository $program,
        private readonly PaperDetails $details,
        private readonly BookPdfRenderer $pdf,
        private readonly \Closure $translate,
        private readonly ?SettingsStore $settings = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function settings(ContextInfo $context, int $editionId): ?BookSettings
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $all = $this->settings?->get($context->id, self::SETTING);
        $saved = is_array($all) && is_array($all[$editionId] ?? null) ? $all[$editionId] : [];
        return BookSettings::fromInput($saved)[0];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ?BookSettings, 1: ValidationErrors}|null null when the edition is not of the journal
     */
    public function saveSettings(ContextInfo $context, int $editionId, array $input, ?int $actorId = null): ?array
    {
        $before = $this->settings($context, $editionId);
        if ($before === null) {
            return null;
        }
        [$book, $errors] = BookSettings::fromInput($input);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $all = $this->settings?->get($context->id, self::SETTING);
        $all = is_array($all) ? $all : [];
        $all[$editionId] = $book->toArray();
        $this->settings?->set($context->id, self::SETTING, $all);
        $this->audit?->log($context->id, $actorId, 'abstract_book', $editionId, 'settings', $before->toArray(), $book->toArray());
        return [$book, $errors];
    }

    /**
     * The papers of the book in groups, in the chosen order.
     *
     * @return array{groups: list<array{title: string, items: list<array{submissionId: int, title: string, authors: list<array{name: string, index: string, affiliation: string}>, abstract: string, keywords: list<string>}>}>, count: int, withoutAbstract: list<array{submissionId: int, title: string}>}|null
     */
    public function content(ContextInfo $context, int $editionId, string $locale, string $order): ?array
    {
        $papers = $this->papers->papers($context, $editionId, $locale);
        if ($papers === null) {
            return null;
        }
        $byId = [];
        foreach ($papers as $p) {
            $byId[$p['submissionId']] = $p;
        }
        $details = $this->details->details(array_keys($byId), $locale);
        $cmp = ProgramService::comparator($locale);
        $byTitle = static function (array $ids) use ($byId, $cmp): array {
            usort($ids, static fn (int $a, int $b): int => $cmp($byId[$a]['title'], $byId[$b]['title']));
            return $ids;
        };

        /** @var list<array{title: string, ids: list<int>}> $groups */
        $groups = [];
        if ($order === 'program') {
            $placed = [];
            foreach ($this->program->sessions($editionId) as $s) {
                $ids = array_values(array_filter(array_keys($s->itemTimes()), static fn (int $id): bool => isset($byId[$id]) && !isset($placed[$id])));
                if ($ids === []) {
                    continue;
                }
                foreach ($ids as $id) {
                    $placed[$id] = true;
                }
                $groups[] = ['title' => (string) $s->text('title')->resolve($locale, $context->primaryLocale) ?: ($this->translate)($s->type->labelKey(), [], $locale), 'ids' => $ids];
            }
            $rest = array_values(array_filter(array_keys($byId), static fn (int $id): bool => !isset($placed[$id])));
            if ($rest !== []) {
                $groups[] = ['title' => $groups === [] ? '' : ($this->translate)(self::K . 'other', [], $locale), 'ids' => $byTitle($rest)];
            }
        } elseif ($order === 'sections') {
            $bySection = [];
            foreach ($papers as $p) {
                $bySection[$p['section']][] = $p['submissionId'];
            }
            uksort($bySection, static fn ($a, $b): int => $cmp((string) $a, (string) $b));
            foreach ($bySection as $title => $ids) {
                $groups[] = ['title' => (string) $title, 'ids' => $byTitle($ids)];
            }
        } else {
            $groups[] = ['title' => '', 'ids' => $byTitle(array_keys($byId))];
        }

        $out = [];
        $without = [];
        foreach ($groups as $g) {
            $items = [];
            foreach ($g['ids'] as $id) {
                $d = $details[$id] ?? ['abstract' => '', 'keywords' => [], 'authors' => []];
                if (trim(strip_tags($d['abstract'])) === '') {
                    $without[] = ['submissionId' => $id, 'title' => $byId[$id]['title']];
                }
                $items[] = ['submissionId' => $id, 'title' => $byId[$id]['title']] + $d;
            }
            $out[] = ['title' => $g['title'], 'items' => $items];
        }
        return ['groups' => $out, 'count' => count($byId), 'withoutAbstract' => $without];
    }

    /**
     * What the manager sees before making the book.
     *
     * @return array{settings: array<string, string|bool>, count: int, groups: list<array{title: string, count: int}>, withoutAbstract: list<array{submissionId: int, title: string}>, isbn: ?string, udc: ?string}|null
     */
    public function overview(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $book = $this->settings($context, $editionId);
        $edition = $this->editions->find($context->id, $editionId);
        if ($book === null || $edition === null) {
            return null;
        }
        $content = $this->content($context, $editionId, $locale, $book->order) ?? ['groups' => [], 'count' => 0, 'withoutAbstract' => []];
        return [
            'settings' => $book->toArray(),
            'count' => $content['count'],
            'groups' => array_map(static fn (array $g): array => ['title' => $g['title'], 'count' => count($g['items'])], $content['groups']),
            'withoutAbstract' => $content['withoutAbstract'],
            'isbn' => $edition->isbn,
            'udc' => $edition->udc,
        ];
    }

    /** @return array{name: string, content: string}|null null when the edition is not of the journal or has no accepted papers */
    public function pdf(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $book = $this->settings($context, $editionId);
        $edition = $this->editions->find($context->id, $editionId);
        if ($book === null || $edition === null) {
            return null;
        }
        $content = $this->content($context, $editionId, $locale, $book->order);
        if ($content === null || $content['count'] === 0) {
            return null;
        }
        $html = AbstractBookHtml::render($book, $this->meta($context, $edition, $locale), $content['groups'], $this->labels($locale));
        $name = (string) $edition->text('name')->resolve($locale, $context->primaryLocale);
        return [
            'name' => 'abstracts-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $edition->path) . '.pdf',
            'content' => $this->pdf->renderBook($html, $book->title !== '' ? $book->title : $name, $book->format),
        ];
    }

    /** @return array{edition: string, isbn: ?string, udc: ?string, year: string, locale: string} */
    private function meta(ContextInfo $context, Edition $edition, string $locale): array
    {
        $starts = $edition->date('startsAt');
        return [
            'edition' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
            'isbn' => $edition->isbn,
            'udc' => $edition->udc,
            'year' => $starts === null ? gmdate('Y') : $starts->format('Y'),
            'locale' => $locale,
        ];
    }

    /** @return array{contents: string, preface: string, index: string, indexNote: string, keywords: string, isbn: string, udc: string} */
    private function labels(string $locale): array
    {
        $out = [];
        foreach (['contents', 'preface', 'index', 'indexNote', 'keywords', 'isbn', 'udc'] as $k) {
            $out[$k] = ($this->translate)(self::K . 'label.' . $k, [], $locale);
        }
        return $out;
    }
}
