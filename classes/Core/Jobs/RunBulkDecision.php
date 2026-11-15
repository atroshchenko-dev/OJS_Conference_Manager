<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Jobs;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\context\Context;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;

/**
 * One item of a bulk decision run (DEC-03): one submission, one letter.
 */
final class RunBulkDecision extends BaseJob
{
    public $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $contextId, public readonly int $batchId, public readonly int $submissionId)
    {
        parent::__construct();
    }

    /**
     * A command-line worker has no journal in its request, and OJS code run
     * by a decision (notifications) reads the journal from the request.
     */
    private static function useContext(int $contextId): void
    {
        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        if ($router === null || $router->getContext($request)?->getId() === $contextId) {
            return;
        }
        $context = app()->get('context')->get($contextId);
        if ($context instanceof Context) {
            $router->_context = $context;
        }
    }

    public function handle(): void
    {
        // A worker started from the command line has not loaded the
        // generic plugins yet: our decision types come from their hooks.
        PluginRegistry::loadCategory('generic', true, $this->contextId);
        self::useContext($this->contextId);
        ServiceFactory::bulkDecisions()->runItem(
            $this->contextId,
            $this->batchId,
            $this->submissionId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
