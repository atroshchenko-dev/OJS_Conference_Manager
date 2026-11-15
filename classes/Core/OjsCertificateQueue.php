<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\CertificateQueue;
use APP\plugins\generic\conferenceManager\classes\Core\Jobs\IssueCertificates;

final class OjsCertificateQueue implements CertificateQueue
{
    public function push(int $contextId, int $editionId, array $participationIds, ?int $actorId, string $locale): void
    {
        dispatch(new IssueCertificates($contextId, $editionId, $participationIds, $actorId, $locale));
    }
}
