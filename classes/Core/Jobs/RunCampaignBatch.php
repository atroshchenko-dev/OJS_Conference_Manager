<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Jobs;

use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\jobs\BaseJob;

/**
 * One portion of a mailing (COM-02). The portion follows the load profile
 * and the hourly letter limit of the installation; while letters are left
 * the job queues itself again behind the work of other journals.
 */
final class RunCampaignBatch extends BaseJob
{
    public $tries = 1;

    public int $timeout = 300;

    /** Seconds to wait when the hourly letter limit is used up. */
    public const LIMIT_WAIT = 300;

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
        $wait = MassWork::wait($this->contextId);
        if ($wait > 0) {
            MassWork::queue(new self($this->contextId, $this->campaignId, $this->cabinetUrl), $wait);
            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $campaigns = ServiceFactory::campaigns();
        $allowed = ServiceFactory::loadPolicy()->mailAllowance($now);
        if ($allowed === 0) {
            // The hourly limit is used up: try again in a few minutes.
            MassWork::queue(new self($this->contextId, $this->campaignId, $this->cabinetUrl), self::LIMIT_WAIT);
            return;
        }
        $taken = $campaigns->runBatch($context, $this->campaignId, $this->cabinetUrl, $now, $allowed);
        if ($taken > 0 && $campaigns->pending($context, $this->campaignId) > 0) {
            MassWork::queue(new self($this->contextId, $this->campaignId, $this->cabinetUrl));
        }
    }
}
