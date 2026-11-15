<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/** Published articles of a journal, for matching an OCS archive (LEG-02). */
interface ArticleDirectory
{
    /**
     * @return list<array{submissionId: int, titles: list<string>, year: ?int}>
     */
    public function published(int $contextId): array;
}
