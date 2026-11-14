<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

enum PublicationMode: string
{
    case None = 'none';
    case OjsIssue = 'ojs_issue';
    case External = 'external';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.edition.publicationMode.' . $this->value;
    }
}
