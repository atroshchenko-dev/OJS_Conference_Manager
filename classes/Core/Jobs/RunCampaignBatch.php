<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Jobs;

use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\jobs\BaseJob;

/**
 * One portion of a mailing (COM-02): up to 50 letters.
 */
final class RunCampaignBatch extends BaseJob
{
    public $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $contextId, public readonly int $campaignId, public readonly string $cabinetUrl)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $context = (new ContextGateway())->info($this->contextId);
        if ($context === null) {
            return;
        }
        ServiceFactory::campaigns()->runBatch($context, $this->campaignId, $this->cabinetUrl, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
