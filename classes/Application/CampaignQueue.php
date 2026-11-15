<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/** Sends the letters of a mailing in the background (COM-02). */
interface CampaignQueue
{
    public function push(int $contextId, int $campaignId, string $cabinetUrl): void;
}
