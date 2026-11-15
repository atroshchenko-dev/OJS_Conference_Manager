<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

/**
 * DEC-08: a light check of a conflict of interest. An editor and an
 * author are in conflict when a part of their affiliations is the same:
 * the institution, or the department, written in the same way up to case,
 * quotes and punctuation. Affiliations are free text in OJS, so this is a
 * warning for the editor, never a block.
 */
final class ConflictCheck
{
    /** Parts shorter than this are not compared ("ЗВО", "НАН"). */
    private const MIN_LENGTH = 6;

    /**
     * @param list<array{userId: int, name: string, affiliations: list<string>}> $editors
     * @param list<array{name: string, affiliations: list<string>}> $authors
     * @return list<array{userId: int, editor: string, author: string, affiliation: string}>
     */
    public static function find(array $editors, array $authors): array
    {
        $out = [];
        foreach ($editors as $e) {
            $mine = [];
            foreach ($e['affiliations'] as $a) {
                foreach (self::parts($a) as $key => $text) {
                    $mine[$key] = $text;
                }
            }
            if ($mine === []) {
                continue;
            }
            foreach ($authors as $author) {
                foreach ($author['affiliations'] as $a) {
                    foreach (self::parts($a) as $key => $text) {
                        if (isset($mine[$key])) {
                            $out[] = ['userId' => $e['userId'], 'editor' => $e['name'], 'author' => $author['name'], 'affiliation' => $text];
                            continue 3;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * "Кафедра права, Університет «КРОК»" → the whole text and its parts,
     * by normalized form.
     *
     * @return array<string, string> normalized => original part
     */
    public static function parts(string $affiliation): array
    {
        $out = [];
        $affiliation = trim($affiliation);
        foreach ([$affiliation, ...(preg_split('/[;,\n]+/u', $affiliation) ?: [])] as $part) {
            $part = trim($part);
            $key = self::normalize($part);
            if (mb_strlen($key) >= self::MIN_LENGTH) {
                $out[$key] ??= $part;
            }
        }
        return $out;
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Apostrophes of all kinds disappear ("Прем'єр", "Прем’єр"); other punctuation separates words.
        $text = (string) preg_replace("/['’ʼ`]/u", '', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim($text);
    }
}
