<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Core\AuditLogGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Mailings, reminders, the dashboard and the audit log
 * (COM-02, COM-03, RPT-01, DAT-01, DAT-05).
 *
 * Registration manager or journal manager:
 * GET  editions/{id}/dashboard                   numbers of the edition
 * GET  editions/{id}/campaigns                   mailings of the edition
 * POST editions/{id}/campaigns/preview           {filter fields} → count and first recipients
 * POST editions/{id}/campaigns/test              {subject, body} → a letter to oneself
 * POST editions/{id}/campaigns                   {subject, body, filter fields} → 202 {id}
 * GET  campaigns/{cid}                           delivery state
 * GET  reminders                                 settings of the reminders
 * PUT  reminders                                 {payment|paper|event: {enabled, days}}
 *
 * Journal manager:
 * GET  audit-log?entity&action&count&offset      who did what and when
 * GET  consent-versions                          versions of the privacy notice with their texts
 */
final class CommunicationRoutes implements RouteModule
{
    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_ASSISTANT];
    }

    public function routes(): void
    {
        Route::get('editions/{editionId}/dashboard', $this->getEditionDashboard(...))->name('conference.dashboard')->whereNumber('editionId');
        Route::get('editions/{editionId}/campaigns', $this->getCampaigns(...))->name('conference.campaigns.list')->whereNumber('editionId');
        Route::post('editions/{editionId}/campaigns/preview', $this->previewCampaign(...))->name('conference.campaigns.preview')->whereNumber('editionId');
        Route::post('editions/{editionId}/campaigns/test', $this->testCampaign(...))->name('conference.campaigns.test')->whereNumber('editionId');
        Route::post('editions/{editionId}/campaigns', $this->startCampaign(...))->name('conference.campaigns.start')->whereNumber('editionId');
        Route::get('campaigns/{campaignId}', $this->getCampaign(...))->name('conference.campaigns.get')->whereNumber('campaignId');
        Route::get('reminders', $this->getReminders(...))->name('conference.reminders.get');
        Route::put('reminders', $this->editReminders(...))->name('conference.reminders.edit');
        Route::get('audit-log', $this->getAuditLog(...))->name('conference.audit.list');
        Route::get('consent-versions', $this->getConsentVersions(...))->name('conference.consent.versions');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function isManager(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    public function getEditionDashboard(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::dashboard()->edition($this->api->contextInfo(), (int) $r->route('editionId'), \PKP\facades\Locale::getLocale());
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function getCampaigns(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $items = ServiceFactory::campaigns()->ofEdition($this->api->contextInfo(), (int) $r->route('editionId'));
        return $items === null ? ConferenceApiController::notFound() : response()->json(['items' => $items], Response::HTTP_OK);
    }

    public function previewCampaign(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::campaigns()->preview($this->api->contextInfo(), (int) $r->route('editionId'), (array) $r->input(), \PKP\facades\Locale::getLocale());
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function testCampaign(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $user = Application::get()->getRequest()->getUser();
        $errors = ServiceFactory::campaigns()->sendTest(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            (array) $r->input(),
            (string) $user?->getEmail(),
            (string) $user?->getFullName(),
            \PKP\facades\Locale::getLocale(),
        );
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty()
            ? response()->json(['sentTo' => (string) $user?->getEmail()], Response::HTTP_OK)
            : ConferenceApiController::errorResponse($errors);
    }

    public function startCampaign(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::campaigns()->start($this->api->contextInfo(), (int) $r->route('editionId'), (array) $r->input(), $this->api->actorId(), \PKP\facades\Locale::getLocale(), self::now());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$id, $errors] = $out;
        return $id === null ? ConferenceApiController::errorResponse($errors) : response()->json(['id' => $id], Response::HTTP_ACCEPTED);
    }

    public function getCampaign(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::campaigns()->status($this->api->contextInfo(), (int) $r->route('campaignId'));
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function getReminders(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(['settings' => ServiceFactory::reminders()->settings($this->api->contextInfo())], Response::HTTP_OK);
    }

    public function editReminders(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::reminders();
        $errors = $service->saveSettings($this->api->contextInfo(), (array) $r->input(), $this->api->actorId());
        return $errors->isEmpty()
            ? response()->json(['settings' => $service->settings($this->api->contextInfo())], Response::HTTP_OK)
            : ConferenceApiController::errorResponse($errors);
    }

    public function getAuditLog(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json((new AuditLogGateway())->list(
            $this->api->contextInfo()->id,
            (string) $r->query('entity', ''),
            (string) $r->query('action', ''),
            (int) $r->query('count', '50'),
            (int) $r->query('offset', '0'),
        ), Response::HTTP_OK);
    }

    public function getConsentVersions(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(['items' => ServiceFactory::consentVersions($this->api->contextInfo())], Response::HTTP_OK);
    }
}
