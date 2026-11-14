<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

enum SubmissionMode: string
{
    case None = 'none';
    case Abstracts = 'abstracts';
    case AbstractsAndPapers = 'abstracts_and_papers';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.edition.submissionMode.' . $this->value;
    }
}
