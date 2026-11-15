<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/** Abstracts, keywords and authors of submissions, for the book of abstracts (PUB-04). */
interface PaperDetails
{
    /**
     * @param list<int> $submissionIds
     * @return array<int, array{abstract: string, keywords: list<string>, authors: list<array{name: string, index: string, affiliation: string}>}>
     *         by submission id; "index" is "Family G." for the author index
     */
    public function details(array $submissionIds, string $locale): array;
}
