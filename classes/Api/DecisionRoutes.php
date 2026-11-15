<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\EditorialGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Conference decisions (DEC-03…06).
 *
 * Journal manager or section editor:
 * GET  editions/{id}/decision-candidates?search&presentationType&section
 *        abstracts waiting for a decision, the decisions and the last runs;
 *        a section editor sees the submissions assigned to them
 * POST editions/{id}/decisions/bulk         {decision, submissionIds} → 202 {batchId}
 * GET  decisions/bulk/{bid}                 progress of a run
 * GET  submissions/{sid}/conference         the conference panel of the submission (DEC-06)
 * PUT  submissions/{sid}/presentation-type  {presentationType} after the decision (DEC-05)
 */
final class DecisionRoutes implements RouteModule
{
    public const SUBMISSION_ACTIONS = ['getSubmissionConference', 'putPresentationType'];

    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    }

    public function routes(): void
    {
        Route::get('editions/{editionId}/decision-candidates', $this->getDecisionCandidates(...))->name('conference.decisions.candidates')->whereNumber('editionId');
        Route::post('editions/{editionId}/decisions/bulk', $this->startBulkDecision(...))->name('conference.decisions.bulk')->whereNumber('editionId');
        Route::get('decisions/bulk/{batchId}', $this->getBulkDecision(...))->name('conference.decisions.bulk.get')->whereNumber('batchId');
        Route::get('submissions/{submissionId}/conference', $this->getSubmissionConference(...))->name('conference.submissions.panel')->whereNumber('submissionId');
        Route::put('submissions/{submissionId}/presentation-type', $this->putPresentationType(...))->name('conference.submissions.presentationType')->whereNumber('submissionId');
    }

    private function isManager(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getDecisionCandidates(Request $r): JsonResponse
    {
        $context = $this->api->contextInfo();
        $editionId = (int) $r->route('editionId');
        $locale = \PKP\facades\Locale::getLocale();
        $service = ServiceFactory::bulkDecisions();
        $items = $service->candidates($context, $editionId, $this->isManager() ? null : $this->api->actorId(), [
            'search' => (string) $r->query('search', ''),
            'presentationType' => (string) $r->query('presentationType', ''),
            'section' => (string) $r->query('section', ''),
        ], $locale);
        if ($items === null) {
            return ConferenceApiController::notFound();
        }
        return response()->json([
            'items' => $items,
            'itemsMax' => count($items),
            'sections' => array_values(array_unique(array_filter(array_column($items, 'section')))),
            'decisions' => $service->decisionOptions($locale),
            'runs' => $service->runs($context, $editionId, $locale) ?? [],
            'maxItems' => \APP\plugins\generic\conferenceManager\classes\Domain\Decision\BulkDecision::MAX_ITEMS,
        ], Response::HTTP_OK);
    }

    public function startBulkDecision(Request $r): JsonResponse
    {
        $actor = $this->api->actorId();
        if ($actor === null) {
            return ConferenceApiController::forbidden();
        }
        $ids = $r->input('submissionIds');
        $result = ServiceFactory::bulkDecisions()->start(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            (int) $r->input('decision'),
            is_array($ids) ? array_values(array_map('intval', $ids)) : [],
            $actor,
            $this->isManager(),
            self::now(),
        );
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        if ($result instanceof ValidationErrors) {
            return ConferenceApiController::errorResponse($result);
        }
        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function getBulkDecision(Request $r): JsonResponse
    {
        $status = ServiceFactory::bulkDecisions()->status($this->api->contextInfo(), (int) $r->route('batchId'), \PKP\facades\Locale::getLocale());
        return $status === null ? ConferenceApiController::notFound() : response()->json($status, Response::HTTP_OK);
    }

    private function canEditSubmission(int $submissionId): bool
    {
        $actor = $this->api->actorId();
        return $this->isManager() || ($actor !== null && EditorialGateway::isAssignedEditor($actor, $submissionId));
    }

    public function getSubmissionConference(Request $r): JsonResponse
    {
        $id = (int) $r->route('submissionId');
        if (!$this->canEditSubmission($id)) {
            return ConferenceApiController::forbidden();
        }
        $panel = ServiceFactory::decisions()->panel($this->api->contextInfo(), $id, \PKP\facades\Locale::getLocale());
        return $panel === null ? ConferenceApiController::notFound() : response()->json($panel, Response::HTTP_OK);
    }

    public function putPresentationType(Request $r): JsonResponse
    {
        $id = (int) $r->route('submissionId');
        if (!$this->canEditSubmission($id)) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $result = ServiceFactory::decisions()->changeType($context, $id, (string) $r->input('presentationType', ''), $this->api->actorId(), self::now());
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        if ($result instanceof ValidationErrors) {
            return ConferenceApiController::errorResponse($result);
        }
        $panel = ServiceFactory::decisions()->panel($context, $id, \PKP\facades\Locale::getLocale());
        return response()->json($result + ['panel' => $panel], Response::HTTP_OK);
    }
}
