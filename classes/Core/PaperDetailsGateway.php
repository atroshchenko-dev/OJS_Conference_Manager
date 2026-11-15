<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\PaperDetails;
use PKP\author\Author;

/**
 * The current publication of each submission, through the OJS
 * repositories: keywords and affiliations are kept in several tables in
 * OJS 3.5 and the objects put them together.
 */
final class PaperDetailsGateway implements PaperDetails
{
    public function details(array $submissionIds, string $locale): array
    {
        $out = [];
        foreach ($submissionIds as $id) {
            $submission = Repo::submission()->get($id);
            $publication = $submission?->getCurrentPublication();
            if ($publication === null) {
                continue;
            }
            $primary = (string) $submission->getData('locale');
            $authors = [];
            foreach ($publication->getData('authors') ?? [] as $author) {
                if (!$author instanceof Author) {
                    continue;
                }
                $family = trim((string) ($author->getFamilyName($locale) ?: $author->getFamilyName($primary) ?: ''));
                $given = trim((string) ($author->getGivenName($locale) ?: $author->getGivenName($primary) ?: ''));
                $authors[] = [
                    'name' => trim($author->getFullName(true, false, $locale)),
                    'index' => $family === '' ? $given : trim($family . ' ' . self::initials($given)),
                    'affiliation' => trim($author->getLocalizedAffiliationNamesAsString($locale, '; ')),
                ];
            }
            $kw = self::pick((array) ($publication->getData('keywords') ?? []), $locale, $primary);
            $out[$id] = [
                'abstract' => (string) self::pick((array) ($publication->getData('abstract') ?? []), $locale, $primary),
                'keywords' => array_values(array_filter(array_map(static fn ($k): string => trim(is_array($k) ? (string) ($k['name'] ?? '') : (string) $k), (array) ($kw ?? [])))),
                'authors' => $authors,
            ];
        }
        return $out;
    }

    /**
     * The value in the language of the book, else in the language of the
     * submission, else in any language that has one.
     *
     * @param array<string, mixed> $values
     */
    private static function pick(array $values, string $locale, string $primary): mixed
    {
        $filled = static fn ($v): bool => is_array($v) ? $v !== [] : trim(strip_tags((string) $v)) !== '';
        foreach ([$locale, $primary] as $l) {
            if (isset($values[$l]) && $filled($values[$l])) {
                return $values[$l];
            }
        }
        foreach ($values as $v) {
            if ($filled($v)) {
                return $v;
            }
        }
        return null;
    }

    private static function initials(string $given): string
    {
        $parts = preg_split('/[\s-]+/u', $given, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_map(static fn (string $p): string => mb_substr($p, 0, 1) . '.', $parts));
    }
}
