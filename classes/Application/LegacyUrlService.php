<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyUrlRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyUrlTemplate;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * LEG-02, LEG-03: old OCS addresses of a journal. The list of OCS papers
 * comes as CSV (one query to the OCS database, see the migration guide);
 * each paper is matched to a published article by the given article id
 * or by its title (and the year in the old address when two articles have
 * the same title).
 */
final class LegacyUrlService
{
    public const SETTING = 'legacyTemplates';
    public const MAX_ROWS = 50000;
    public const MAX_BYTES = 10485760;
    private const K = 'plugins.generic.conferenceManager.legacy.error.';

    /** CSV columns and the names they may have. */
    private const COLUMNS = [
        'ocsPaperId' => ['ocs_paper_id', 'paper_id', 'id'],
        'conference' => ['conference', 'conference_path'],
        'schedConf' => ['sched_conf', 'sched_conf_path', 'schedconf'],
        'title' => ['title'],
        'submissionId' => ['submission_id', 'ojs_submission_id', 'article_id'],
    ];

    public function __construct(
        private readonly LegacyUrlRepository $urls,
        private readonly ArticleDirectory $articles,
        private readonly ?SettingsStore $settings = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @return list<string> */
    public function patterns(ContextInfo $context): array
    {
        $v = $this->settings?->get($context->id, self::SETTING);
        return is_array($v) && $v !== [] ? array_values(array_map('strval', $v)) : LegacyUrlTemplate::DEFAULTS;
    }

    /** @return list<LegacyUrlTemplate> */
    public function templates(ContextInfo $context): array
    {
        return LegacyUrlTemplate::parseAll($this->patterns($context))[0];
    }

    /**
     * @param mixed $patterns list of strings; an empty list restores the defaults
     * @return array<int, string> message keys of bad patterns by line (from 1); empty when saved
     */
    public function savePatterns(ContextInfo $context, mixed $patterns, ?int $actorId = null): array
    {
        $list = is_array($patterns) ? array_values(array_filter(array_map(static fn ($p): string => trim((string) $p), $patterns), static fn (string $p): bool => $p !== '')) : [];
        [$templates, $bad] = LegacyUrlTemplate::parseAll($list);
        if ($bad !== []) {
            return array_map(static fn (string $key): string => self::K . 'template.' . $key, $bad);
        }
        $before = $this->patterns($context);
        $value = array_map(static fn (LegacyUrlTemplate $t): string => $t->pattern, $templates);
        $this->settings?->set($context->id, self::SETTING, $value);
        $this->audit?->log($context->id, $actorId, 'legacy_urls', null, 'templates', ['templates' => $before], ['templates' => $value === [] ? LegacyUrlTemplate::DEFAULTS : $value]);
        return [];
    }

    /**
     * @return array{templates: list<string>, defaults: list<string>, total: int, matched: int, unmatched: list<array{ocsPaperId: int, path: string}>}
     */
    public function overview(ContextInfo $context): array
    {
        $rows = $this->urls->all($context->id);
        $unmatched = array_values(array_filter($rows, static fn (array $r): bool => $r['submissionId'] === null));
        return [
            'templates' => $this->patterns($context),
            'defaults' => LegacyUrlTemplate::DEFAULTS,
            'total' => count($rows),
            'matched' => count($rows) - count($unmatched),
            'unmatched' => array_map(static fn (array $r): array => ['ocsPaperId' => $r['ocsPaperId'], 'path' => $r['conference'] . '/' . $r['schedConf']], array_slice($unmatched, 0, 200)),
        ];
    }

    /**
     * @return array{rows: int, matched: int, unmatched: list<array{line: int, ocsPaperId: int, title: string}>, ambiguous: list<array{line: int, ocsPaperId: int, title: string, submissionIds: list<int>}>, errors: list<array{line: int, key: string, value?: string}>}
     */
    public function import(ContextInfo $context, string $csv, ?int $actorId = null): array
    {
        $report = ['rows' => 0, 'matched' => 0, 'unmatched' => [], 'ambiguous' => [], 'errors' => []];
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            $report['errors'][] = ['line' => 0, 'key' => self::K . 'size'];
            return $report;
        }
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $csv));
        $lines = explode("\n", $csv);
        $header = array_shift($lines);
        $delimiter = self::delimiter((string) $header);
        $columns = self::columns(str_getcsv((string) $header, $delimiter, '"', ''));
        if (!isset($columns['ocsPaperId'], $columns['conference'], $columns['schedConf']) || (!isset($columns['title']) && !isset($columns['submissionId']))) {
            $report['errors'][] = ['line' => 1, 'key' => self::K . 'header'];
            return $report;
        }
        if (count($lines) > self::MAX_ROWS + 1) {
            $report['errors'][] = ['line' => 0, 'key' => self::K . 'rows'];
            return $report;
        }
        [$byTitle, $published] = $this->index($context->id);
        $rows = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $n = $i + 2;
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $cell = static fn (string $name): string => isset($columns[$name]) ? trim((string) ($cells[$columns[$name]] ?? '')) : '';
            $id = filter_var($cell('ocsPaperId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $conference = $cell('conference');
            $schedConf = $cell('schedConf');
            if ($id === false) {
                $report['errors'][] = ['line' => $n, 'key' => self::K . 'id'];
                continue;
            }
            if (!self::segment($conference) || !self::segment($schedConf)) {
                $report['errors'][] = ['line' => $n, 'key' => self::K . 'path'];
                continue;
            }
            if (isset($seen[$id])) {
                $report['errors'][] = ['line' => $n, 'key' => self::K . 'duplicate'];
                continue;
            }
            $seen[$id] = true;
            $title = mb_substr($cell('title'), 0, 500);
            $submissionId = null;
            $given = $cell('submissionId');
            if ($given !== '') {
                $sid = filter_var($given, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($sid === false || !isset($published[$sid])) {
                    $report['errors'][] = ['line' => $n, 'key' => self::K . 'submission'];
                    continue;
                }
                $submissionId = $sid;
            } elseif ($title !== '') {
                $candidates = $byTitle[self::normalizeTitle($title)] ?? [];
                if (count($candidates) > 1 && preg_match('/(?:19|20)\d{2}/', $schedConf, $m) === 1) {
                    $year = (int) $m[0];
                    // The year of the event, or the next one (proceedings often come out later).
                    foreach ([$year, $year + 1] as $y) {
                        $sameYear = array_values(array_filter($candidates, static fn (int $c): bool => $published[$c] === $y));
                        if ($sameYear !== []) {
                            $candidates = $sameYear;
                            break;
                        }
                    }
                }
                if (count($candidates) === 1) {
                    $submissionId = $candidates[0];
                } elseif (count($candidates) > 1) {
                    $report['ambiguous'][] = ['line' => $n, 'ocsPaperId' => $id, 'title' => $title, 'submissionIds' => $candidates];
                }
            }
            if ($submissionId === null && !self::inList($report['ambiguous'], $id)) {
                $report['unmatched'][] = ['line' => $n, 'ocsPaperId' => $id, 'title' => $title];
            }
            $rows[] = ['ocsPaperId' => $id, 'conference' => $conference, 'schedConf' => $schedConf, 'submissionId' => $submissionId];
        }
        foreach ($this->urls->save($context->id, $rows) as $path) {
            $report['errors'][] = ['line' => 0, 'key' => self::K . 'taken', 'value' => $path];
        }
        $report['rows'] = count($rows);
        $report['matched'] = count(array_filter($rows, static fn (array $r): bool => $r['submissionId'] !== null));
        $report['unmatched'] = array_slice($report['unmatched'], 0, 500);
        $report['ambiguous'] = array_slice($report['ambiguous'], 0, 500);
        $this->audit?->log($context->id, $actorId, 'legacy_urls', null, 'import', null, ['rows' => $report['rows'], 'matched' => $report['matched']]);
        return $report;
    }

    public function clear(ContextInfo $context, ?int $actorId = null): int
    {
        $n = $this->urls->clear($context->id);
        $this->audit?->log($context->id, $actorId, 'legacy_urls', null, 'clear', ['rows' => $n], null);
        return $n;
    }

    /**
     * LEG-03: the article of an old address, if the address fits a
     * template and the paper is matched.
     *
     * @return array{ocsPaperId: int, submissionId: ?int}|null null when the address is not an OCS paper address
     */
    public function resolve(ContextInfo $context, string $url): ?array
    {
        $path = LegacyUrlTemplate::normalize($url);
        foreach ($this->templates($context) as $t) {
            $m = $t->match($path);
            if ($m === null) {
                continue;
            }
            $row = $this->urls->find($context->id, $m['id']);
            if ($row !== null && ($m['conference'] === null || strcasecmp($m['conference'], $row['conference']) === 0)
                && ($m['schedConf'] === null || strcasecmp($m['schedConf'], $row['schedConf']) === 0)) {
                return ['ocsPaperId' => $m['id'], 'submissionId' => $row['submissionId']];
            }
            return ['ocsPaperId' => $m['id'], 'submissionId' => null];
        }
        return null;
    }

    /**
     * Matched papers for the redirect map.
     *
     * @return list<array{id: int, conference: string, schedConf: string, submissionId: int}>
     */
    public function mapPapers(ContextInfo $context): array
    {
        $out = [];
        foreach ($this->urls->all($context->id) as $r) {
            if ($r['submissionId'] !== null) {
                $out[] = ['id' => $r['ocsPaperId'], 'conference' => $r['conference'], 'schedConf' => $r['schedConf'], 'submissionId' => $r['submissionId']];
            }
        }
        return $out;
    }

    public static function normalizeTitle(string $title): string
    {
        $t = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = mb_strtolower($t, 'UTF-8');
        $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        return trim($t);
    }

    /**
     * Titles of published articles => ids, and article id => year.
     *
     * @return array{0: array<string, list<int>>, 1: array<int, ?int>}
     */
    private function index(int $contextId): array
    {
        $byTitle = [];
        $published = [];
        foreach ($this->articles->published($contextId) as $a) {
            $published[$a['submissionId']] = $a['year'];
            foreach (array_unique(array_map(self::normalizeTitle(...), $a['titles'])) as $t) {
                if ($t !== '') {
                    $byTitle[$t][] = $a['submissionId'];
                }
            }
        }
        return [$byTitle, $published];
    }

    /** @param list<array{ocsPaperId: int}> $list */
    private static function inList(array $list, int $id): bool
    {
        foreach ($list as $item) {
            if ($item['ocsPaperId'] === $id) {
                return true;
            }
        }
        return false;
    }

    private static function segment(string $value): bool
    {
        return $value !== '' && strlen($value) <= 100 && preg_match('#^[^/\s?\#%]+$#u', $value) === 1;
    }

    private static function delimiter(string $header): string
    {
        $counts = [',' => substr_count($header, ','), ';' => substr_count($header, ';'), "\t" => substr_count($header, "\t")];
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * @param list<?string> $header
     * @return array<string, int>
     */
    private static function columns(array $header): array
    {
        $out = [];
        foreach ($header as $i => $name) {
            $name = strtolower(trim((string) $name));
            foreach (self::COLUMNS as $key => $aliases) {
                if (!isset($out[$key]) && in_array($name, $aliases, true)) {
                    $out[$key] = $i;
                }
            }
        }
        return $out;
    }
}
