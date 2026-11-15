<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Book;

/**
 * PUB-04: the book of abstracts as HTML for mPDF: a title page, the
 * imprint page, a table of contents by groups (sessions or sections) with
 * page numbers, the abstracts in order with numbers, and an author index
 * by the numbers of the abstracts. Page numbers and the contents are made
 * by mPDF from <tocentry>; the index is sorted here with the collation of
 * the language, which mPDF would leave to the locales of the server.
 */
final class AbstractBookHtml
{
    /** Tags kept from the abstracts written in the OJS editor. */
    private const ALLOWED = '<p><br><i><em><b><strong><sup><sub><u><ul><ol><li>';

    /**
     * @param array{edition: string, isbn: ?string, udc: ?string, year: string, locale?: string} $meta
     * @param list<array{title: string, items: list<array{title: string, authors: list<array{name: string, index?: string, affiliation: string}>, abstract: string, keywords: list<string>}>}> $groups
     * @param array{contents: string, preface: string, index: string, indexNote: string, keywords: string, isbn: string, udc: string} $labels
     */
    public static function render(BookSettings $s, array $meta, array $groups, array $labels): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = static fn (string $v): string => nl2br($e($v), false);
        $small = $s->format === 'A5';
        $out = ['<style>'
            . 'body{font-family:dejavuserif;font-size:' . ($small ? '9.5' : '11') . 'pt;line-height:1.3;}'
            . '.header{text-align:center;font-size:' . ($small ? '9' : '10.5') . 'pt;text-transform:uppercase;}'
            . '.title{text-align:center;font-size:' . ($small ? '16' : '20') . 'pt;font-weight:bold;margin:30mm 0 6mm;}'
            . '.subtitle{text-align:center;font-size:' . ($small ? '11' : '13') . 'pt;}'
            . '.place{text-align:center;position:absolute;bottom:' . ($small ? '15' : '20') . 'mm;left:0;right:0;}'
            . '.imprint{font-size:' . ($small ? '8.5' : '10') . 'pt;}'
            . '.codes{margin-bottom:6mm;}'
            . 'h1.group{font-size:' . ($small ? '12' : '14') . 'pt;text-align:center;margin:0 0 5mm;}'
            . '.paper{margin:0 0 6mm;page-break-inside:avoid;}'
            . '.paper h2{font-size:' . ($small ? '10.5' : '12') . 'pt;margin:0 0 1.5mm;}'
            . '.authors{font-style:italic;margin:0 0 .5mm;}'
            . '.affiliation{font-size:' . ($small ? '8.5' : '10') . 'pt;margin:0 0 2mm;}'
            . '.abstract{text-align:justify;}.abstract p{margin:0 0 1.5mm;text-indent:5mm;}'
            . '.kw{font-size:' . ($small ? '8.5' : '10') . 'pt;margin-top:1mm;}'
            . '.index{font-size:' . ($small ? '8.5' : '10') . 'pt;}.letter{font-weight:bold;margin-top:2mm;page-break-after:avoid;}'
            . 'div.mpdf_toc{font-family:dejavuserif;}span.mpdf_toc_t_level_1,span.mpdf_toc_p_level_1{font-weight:normal;font-style:normal;}'
            . '.pageno{text-align:center;font-size:' . ($small ? '8' : '9') . 'pt;}'
            . '</style>'
            . '<htmlpagefooter name="cmPage"><div class="pageno">{PAGENO}</div></htmlpagefooter>'];

        // Title page.
        $out[] = '<div class="header">' . $lines($s->header) . '</div>';
        $out[] = '<div class="title">' . $lines($s->title !== '' ? $s->title : $meta['edition']) . '</div>';
        if ($s->subtitle !== '') {
            $out[] = '<div class="subtitle">' . $lines($s->subtitle) . '</div>';
        }
        $out[] = '<div class="place">' . $e(trim($s->place . ($s->place !== '' ? ' — ' : '') . $meta['year'])) . '</div>';

        // Imprint page; page numbers from here on.
        $out[] = '<pagebreak odd-footer-name="html_cmPage" even-footer-name="html_cmPage" odd-footer-value="1" even-footer-value="1" />';
        $codes = [];
        if (($meta['udc'] ?? '') !== '') {
            $codes[] = $e($labels['udc']) . ' ' . $e((string) $meta['udc']);
        }
        if (($meta['isbn'] ?? '') !== '') {
            $codes[] = $e($labels['isbn']) . ' ' . $e((string) $meta['isbn']);
        }
        $out[] = '<div class="imprint">' . ($codes !== [] ? '<div class="codes">' . implode('<br>', $codes) . '</div>' : '') . $lines($s->imprint) . '</div>';

        // Contents, then the preface.
        $out[] = '<tocpagebreak links="on" toc-preHTML="' . $e('<h1 class="group">' . $e($labels['contents']) . '</h1>') . '" />';
        if ($s->preface !== '') {
            $out[] = '<tocentry content="' . $e($labels['preface']) . '" level="0" />';
            $out[] = '<h1 class="group">' . $e($labels['preface']) . '</h1><div class="abstract">' . self::paragraphs($s->preface, $e) . '</div><pagebreak />';
        }

        $n = 0;
        $index = [];
        foreach ($groups as $gi => $group) {
            if ($gi > 0) {
                $out[] = '<pagebreak />';
            }
            if ($group['title'] !== '') {
                $out[] = '<tocentry content="' . $e($group['title']) . '" level="0" />';
                $out[] = '<h1 class="group">' . $e($group['title']) . '</h1>';
            }
            foreach ($group['items'] as $item) {
                $n++;
                $names = array_map(static fn (array $a): string => $a['name'], $item['authors']);
                $affiliations = array_values(array_unique(array_filter(array_map(static fn (array $a): string => $a['affiliation'], $item['authors']))));
                $html = '<div class="paper">';
                $html .= '<tocentry content="' . $e($n . '. ' . $item['title'] . ($names !== [] ? ' (' . implode(', ', $names) . ')' : '')) . '" level="1" />';
                foreach ($item['authors'] as $a) {
                    $key = ($a['index'] ?? '') !== '' ? $a['index'] : self::indexName($a['name']);
                    if ($key !== '' && !in_array($n, $index[$key] ?? [], true)) {
                        $index[$key][] = $n;
                    }
                }
                $html .= '<h2>' . $n . '. ' . $e($item['title']) . '</h2>';
                if ($names !== []) {
                    $html .= '<div class="authors">' . $e(implode(', ', $names)) . '</div>';
                }
                if ($affiliations !== []) {
                    $html .= '<div class="affiliation">' . $e(implode('; ', $affiliations)) . '</div>';
                }
                $html .= '<div class="abstract">' . self::clean($item['abstract']) . '</div>';
                if ($s->keywords && $item['keywords'] !== []) {
                    $html .= '<div class="kw"><b>' . $e($labels['keywords']) . ':</b> ' . $e(implode(', ', $item['keywords'])) . '</div>';
                }
                $out[] = $html . '</div>';
            }
        }

        if ($s->authorIndex && $n > 0) {
            $out[] = '<pagebreak />';
            $out[] = '<tocentry content="' . $e($labels['index']) . '" level="0" />';
            $out[] = '<h1 class="group">' . $e($labels['index']) . '</h1>';
            $out[] = '<p class="kw">' . $e($labels['indexNote']) . '</p>' . self::index($index, $meta['locale'] ?? 'uk', $e);
        }
        return implode("\n", $out);
    }

    /**
     * @param array<string, list<int>> $index
     * @param \Closure(string): string $e
     */
    private static function index(array $index, string $locale, \Closure $e): string
    {
        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        uksort($index, static fn ($a, $b): int => $collator === null ? strcmp(mb_strtolower((string) $a), mb_strtolower((string) $b)) : (int) $collator->compare((string) $a, (string) $b));
        $out = '<div class="index">';
        $letter = null;
        foreach ($index as $name => $numbers) {
            $first = mb_strtoupper(mb_substr((string) $name, 0, 1));
            if ($first !== $letter) {
                $letter = $first;
                $out .= '<div class="letter">' . $e($first) . '</div>';
            }
            $out .= '<div>' . $e((string) $name) . "\u{00A0} " . implode(', ', $numbers) . '</div>';
        }
        return $out . '</div>';
    }

    /** "Petro Ivanenko" → "Ivanenko P." for the index. */
    public static function indexName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) < 2) {
            return trim($name);
        }
        $family = array_pop($parts);
        $initials = array_map(static fn (string $p): string => mb_substr($p, 0, 1) . '.', $parts);
        return $family . ' ' . implode(' ', $initials);
    }

    /** Abstract HTML from the OJS editor with only simple formatting. */
    public static function clean(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = strip_tags($html, self::ALLOWED);
        // No attributes: styles, classes and event handlers are dropped.
        $html = (string) preg_replace('/<(\/?)(p|br|i|em|b|strong|sup|sub|u|ul|ol|li)\b[^>]*>/i', '<$1$2>', $html);
        return str_contains($html, '<p>') ? $html : '<p>' . $html . '</p>';
    }

    /** @param \Closure(string): string $e */
    private static function paragraphs(string $text, \Closure $e): string
    {
        $out = '';
        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $p) {
            $out .= '<p>' . nl2br($e(trim($p)), false) . '</p>';
        }
        return $out;
    }
}
