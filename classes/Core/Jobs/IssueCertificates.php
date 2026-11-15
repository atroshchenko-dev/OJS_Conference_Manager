<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Jobs;

use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\jobs\BaseJob;

/**
 * One portion of a batch issue (CRT-06): up to 25 certificates. Issuing is
 * idempotent (one certificate of a type per participant), so a repeated job
 * issues nothing twice.
 */
final class IssueCertificates extends BaseJob
{
    public $tries = 1;

    public int $timeout = 600;

    /** @param list<int> $participationIds */
    public function __construct(
        public readonly int $contextId,
        public readonly int $editionId,
        public readonly array $participationIds,
        public readonly ?int $actorId,
        public readonly string $locale,
        /** @var list<int> the next portions */
        public readonly array $rest = [],
    ) {
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
            MassWork::queue(new self($this->contextId, $this->editionId, $this->participationIds, $this->actorId, $this->locale, $this->rest), $wait);
            return;
        }
        ServiceFactory::certificates()->issue($context, $this->editionId, $this->participationIds, $this->actorId, new DateTimeImmutable('now', new DateTimeZone('UTC')), $this->locale);
        if ($this->rest !== []) {
            $size = ServiceFactory::loadPolicy()->batchSize();
            MassWork::queue(new self($this->contextId, $this->editionId, array_slice($this->rest, 0, $size), $this->actorId, $this->locale, array_slice($this->rest, $size)));
        }
    }
}
