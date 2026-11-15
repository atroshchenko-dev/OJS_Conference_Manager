<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

/**
 * PUB-02: Google Scholar tags of a conference paper. The keys are the header
 * names the OJS Google Scholar plugin uses, so "citation_journal_title" is
 * replaced by "citation_conference_title" and the proceedings' ISBN is added.
 */
final class ScholarTags
{
    /**
     * @return array<string, string> header name => meta element
     */
    public static function of(Edition $edition, string $locale, string $fallbackLocale, ?string $publisher): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = (string) $edition->text('name')->resolve($locale, $fallbackLocale);
        $tags = [];
        if ($title !== '') {
            $tags['googleScholarJournalTitle'] = '<meta name="citation_conference_title" content="' . $e($title) . '"/>';
        }
        if ($edition->isbn !== null && $edition->isbn !== '') {
            $tags['googleScholarIsbn'] = '<meta name="citation_isbn" content="' . $e($edition->isbn) . '"/>';
        }
        if ($publisher !== null && $publisher !== '') {
            $tags['googleScholarPublisher'] = '<meta name="citation_publisher" content="' . $e($publisher) . '"/>';
        }
        return $tags;
    }
}
