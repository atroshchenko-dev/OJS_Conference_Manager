<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\facades\Locale;
use PKP\security\Role;

/**
 * Attendance and certificates (ATT-01, ATT-02, EVT-05, CRT-01, CRT-02,
 * CRT-04, CRT-05, CRT-07).
 *
 * Registration manager or journal manager:
 * PUT  editions/{id}/attendance                  {participationIds, attended}
 * POST editions/{id}/attendance/import           {csv | csvBase64, threshold, apply} → preview or result
 * GET  editions/{id}/certificate-candidates      who gets which certificate, or why none
 * POST editions/{id}/certificates                {participationIds} → issued numbers
 * GET  editions/{id}/certificates                issued certificates
 * POST editions/{id}/certificates/batch          {participationIds?} everyone eligible, in the queue when many (CRT-06)
 * POST editions/{id}/reviewers                   adds reviewers of the edition's papers (CRT-11)
 * POST certificates/{id}/revoke                  {reason} (CRT-09)
 * POST certificates/{id}/reissue                 {reason?} → the new certificate (CRT-09)
 * GET  editions/{id}/vector-export?all=1         CSV of professional development documents (CRT-10)
 *
 * Journal manager:
 * GET  certificate-templates                     templates per type with the built-in one
 * PUT  certificate-templates/{type}              {html}; empty html returns to the built-in
 * POST certificate-templates/{type}/preview      {html} → HTML with sample values
 * GET  certificate-settings                      {series, vectorEnabled, features}
 * PUT  certificate-settings                      {series, vectorEnabled?}
 */
final class CertificateRoutes implements RouteModule
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
        Route::put('editions/{editionId}/attendance', $this->markAttendance(...))->name('conference.attendance.mark')->whereNumber('editionId');
        Route::post('editions/{editionId}/attendance/import', $this->importAttendance(...))->name('conference.attendance.import')->whereNumber('editionId');
        Route::get('editions/{editionId}/certificate-candidates', $this->getCertificateCandidates(...))->name('conference.certificates.candidates')->whereNumber('editionId');
        Route::post('editions/{editionId}/certificates', $this->issueCertificates(...))->name('conference.certificates.issue')->whereNumber('editionId');
        Route::get('editions/{editionId}/certificates', $this->getEditionCertificates(...))->name('conference.certificates.list')->whereNumber('editionId');
        Route::post('editions/{editionId}/certificates/batch', $this->issueCertificateBatch(...))->name('conference.certificates.batch')->whereNumber('editionId');
        Route::post('editions/{editionId}/reviewers', $this->addEditionReviewers(...))->name('conference.certificates.reviewers')->whereNumber('editionId');
        Route::post('certificates/{certificateId}/revoke', $this->revokeCertificate(...))->name('conference.certificates.revoke')->whereNumber('certificateId');
        Route::post('certificates/{certificateId}/reissue', $this->reissueCertificate(...))->name('conference.certificates.reissue')->whereNumber('certificateId');
        Route::get('editions/{editionId}/vector-export', $this->exportVector(...))->name('conference.certificates.vector')->whereNumber('editionId');
        Route::get('certificate-templates', $this->getCertificateTemplates(...))->name('conference.certificateTemplates.list');
        Route::put('certificate-templates/{type}', $this->editCertificateTemplate(...))->name('conference.certificateTemplates.edit')->where('type', '[a-z]+');
        Route::post('certificate-templates/{type}/preview', $this->previewCertificateTemplate(...))->name('conference.certificateTemplates.preview')->where('type', '[a-z]+');
        Route::get('certificate-settings', $this->getCertificateSettings(...))->name('conference.certificateSettings.get');
        Route::put('certificate-settings', $this->editCertificateSettings(...))->name('conference.certificateSettings.edit');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function isManager(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    public function markAttendance(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $changed = ServiceFactory::attendance()->mark(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            array_values((array) $r->input('participationIds', [])),
            filter_var($r->input('attended', false), FILTER_VALIDATE_BOOLEAN),
            $this->api->actorId(),
            self::now(),
        );
        return $changed === null ? ConferenceApiController::notFound() : response()->json(['changed' => $changed], Response::HTTP_OK);
    }

    public function importAttendance(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        // A file in base64 keeps the bytes of UTF-16 reports (Teams).
        $encoded = $r->input('csvBase64');
        $csv = is_string($encoded) ? (string) base64_decode($encoded, true) : $r->input('csv', '');
        $out = ServiceFactory::attendance()->import(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            is_string($csv) ? $csv : '',
            $r->input('threshold'),
            filter_var($r->input('apply', false), FILTER_VALIDATE_BOOLEAN),
            Locale::getLocale(),
            $this->api->actorId(),
            self::now(),
        );
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$result, $errors] = $out;
        return $result === null ? ConferenceApiController::errorResponse($errors) : response()->json($result, Response::HTTP_OK);
    }

    public function getCertificateCandidates(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::certificates();
        $items = $service->candidates($this->api->contextInfo(), (int) $r->route('editionId'), Locale::getLocale());
        return $items === null ? ConferenceApiController::notFound() : response()->json([
            'items' => $items,
            'threshold' => ServiceFactory::attendance()->threshold($this->api->contextInfo()),
        ], Response::HTTP_OK);
    }

    public function issueCertificates(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::certificates()->issue(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            array_values((array) $r->input('participationIds', [])),
            $this->api->actorId(),
            self::now(),
            Locale::getLocale(),
        );
        return $out === null ? ConferenceApiController::notFound() : response()->json(['items' => $out], Response::HTTP_OK);
    }

    public function getEditionCertificates(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $items = ServiceFactory::certificates()->ofEdition($this->api->contextInfo(), (int) $r->route('editionId'), Locale::getLocale());
        return $items === null ? ConferenceApiController::notFound() : response()->json(['items' => $items], Response::HTTP_OK);
    }

    public function getCertificateTemplates(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::certificates();
        return response()->json([
            'items' => $service->templates($this->api->contextInfo(), Locale::getLocale()),
            'variables' => \APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateDocument::VARIABLES,
        ], Response::HTTP_OK);
    }

    public function editCertificateTemplate(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $html = $r->input('html', '');
        $errors = ServiceFactory::certificates()->saveTemplate($this->api->contextInfo(), (string) $r->route('type'), is_string($html) ? $html : '', $this->api->actorId(), self::now());
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty() ? response()->json(['saved' => true], Response::HTTP_OK) : ConferenceApiController::errorResponse($errors);
    }

    public function previewCertificateTemplate(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $html = $r->input('html', '');
        $out = ServiceFactory::certificates()->preview($this->api->contextInfo(), (string) $r->route('type'), is_string($html) ? $html : '', Locale::getLocale());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$rendered, $errors] = $out;
        return $rendered === null ? ConferenceApiController::errorResponse($errors) : response()->json(['html' => $rendered], Response::HTTP_OK);
    }

    public function getCertificateSettings(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(self::settingsOf(ServiceFactory::certificates(), $this->api->contextInfo()), Response::HTTP_OK);
    }

    /** @return array{series: string, vectorEnabled: bool, professionalDevelopment: bool} */
    private static function settingsOf(\APP\plugins\generic\conferenceManager\classes\Application\CertificateService $service, \APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $context): array
    {
        return [
            'series' => $service->series($context),
            'vectorEnabled' => $service->vectorEnabled($context),
            'professionalDevelopment' => $service->professionalDevelopmentAllowed($context),
        ];
    }

    public function editCertificateSettings(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::certificates();
        $context = $this->api->contextInfo();
        $errors = $service->saveSeries($context, (string) $r->input('series', $service->series($context)), $this->api->actorId());
        if (!$errors->isEmpty()) {
            return ConferenceApiController::errorResponse($errors);
        }
        if ($r->has('vectorEnabled')) {
            $service->setVectorEnabled($context, filter_var($r->input('vectorEnabled'), FILTER_VALIDATE_BOOLEAN), $this->api->actorId());
        }
        return response()->json(self::settingsOf($service, $context), Response::HTTP_OK);
    }

    public function issueCertificateBatch(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $ids = $r->input('participationIds');
        $out = ServiceFactory::certificates()->issueBatch(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            is_array($ids) ? array_values($ids) : null,
            new \APP\plugins\generic\conferenceManager\classes\Core\OjsCertificateQueue(),
            $this->api->actorId(),
            self::now(),
            Locale::getLocale(),
        );
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function addEditionReviewers(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::certificates()->addReviewers($this->api->contextInfo(), (int) $r->route('editionId'), $this->api->actorId(), self::now(), Locale::getLocale());
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function revokeCertificate(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $reason = $r->input('reason', '');
        $errors = ServiceFactory::certificates()->revoke($this->api->contextInfo(), (int) $r->route('certificateId'), is_string($reason) ? $reason : '', $this->api->actorId(), self::now());
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty() ? response()->json(['revoked' => true], Response::HTTP_OK) : ConferenceApiController::errorResponse($errors);
    }

    public function reissueCertificate(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $reason = $r->input('reason', '');
        $out = ServiceFactory::certificates()->reissue($this->api->contextInfo(), (int) $r->route('certificateId'), is_string($reason) ? $reason : '', $this->api->actorId(), self::now());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$created, $errors] = $out;
        return $created === null ? ConferenceApiController::errorResponse($errors) : response()->json($created, Response::HTTP_OK);
    }

    public function exportVector(Request $r): Response|JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::certificates();
        $context = $this->api->contextInfo();
        if (!$service->vectorEnabled($context)) {
            return ConferenceApiController::notFound();
        }
        $editionId = (int) $r->route('editionId');
        $csv = $service->vectorCsv($context, $editionId, filter_var($r->query('all', ''), FILTER_VALIDATE_BOOLEAN), $this->api->actorId(), self::now(), Locale::getLocale());
        if ($csv === null) {
            return ConferenceApiController::notFound();
        }
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vector-' . $editionId . '-' . gmdate('Ymd') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
