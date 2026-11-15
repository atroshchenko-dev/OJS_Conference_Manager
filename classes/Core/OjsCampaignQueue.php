<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\CampaignQueue;
use APP\plugins\generic\conferenceManager\classes\Core\Jobs\RunCampaignBatch;

final class OjsCampaignQueue implements CampaignQueue
{
    public function push(int $contextId, int $campaignId, string $cabinetUrl): void
    {
        dispatch(new RunCampaignBatch($contextId, $campaignId, $cabinetUrl));
    }
}
