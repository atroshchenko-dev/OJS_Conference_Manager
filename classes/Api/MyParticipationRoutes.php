<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\PersonGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * The person's own participations (REG-05, REG-06, REG-08), for any
 * logged-in user of the context:
 *
 * POST editions/{id}/listeners {categoryId, participationForm, consent, position, affiliation, minor, guardianConsent}
 * GET  me/participations
 * POST me/participations/{pid}/cancel   before the registration deadline
 * GET  me/invoices                      own invoices with instructions
 * GET  me/invoices/{iid}/pdf            the document of an own invoice
 * POST me/invoices/{iid}/proofs         multipart "file": a receipt (FEE-09)
 * GET  me/proofs/{prid}/file            an own receipt
 *
 * Only participations found by the user's own id or email are shown or
 * cancelled; any other id gives 403.
 */
final class MyParticipationRoutes implements RouteModule
{
    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [
            Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_READER,
        ];
    }

    public function routes(): void
    {
        Route::post('editions/{editionId}/listeners', $this->addListener(...))->name('conference.listeners.add')->whereNumber('editionId');
        Route::get('me/participations', $this->getMyParticipations(...))->name('conference.me.participations');
        Route::get('me/invoices', $this->getMyInvoices(...))->name('conference.me.invoices');
        Route::get('me/invoices/{invoiceId}/pdf', $this->getMyInvoicePdf(...))->name('conference.me.invoices.pdf')->whereNumber('invoiceId');
        Route::post('me/invoices/{invoiceId}/proofs', $this->addMyProof(...))->name('conference.me.proofs.add')->whereNumber('invoiceId');
        Route::get('me/proofs/{proofId}/file', $this->getMyProofFile(...))->name('conference.me.proofs.file')->whereNumber('proofId');
        Route::post('me/participations/{participationId}/cancel', $this->cancelMyParticipation(...))->name('conference.me.participations.cancel')->whereNumber('participationId');
    }

    public function addListener(Request $r): JsonResponse
    {
        $context = $this->api->contextInfo();
        $userId = (int) $this->api->actorId();
        $locale = \PKP\facades\Locale::getLocale();
        $person = (new PersonGateway())->of($userId, $locale, self::text($r->input('position')), self::text($r->input('affiliation')));
        if ($person === null) {
            return ConferenceApiController::forbidden();
        }
        [$result, $errors] = ServiceFactory::participants()->registerListener(
            $context,
            (int) $r->route('editionId'),
            $person,
            [
                'categoryId' => $r->input('categoryId'),
                'participationForm' => $r->input('participationForm'),
                'consent' => $r->input('consent'),
                'minor' => $r->input('minor'),
                'guardianConsent' => $r->input('guardianConsent'),
            ],
            ServiceFactory::consentText($context, $locale)->version,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->api->remoteAddr(),
        );
        if ($result === null) {
            return ConferenceApiController::errorResponse($errors, $errors->has('editionId') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST);
        }
        return response()->json($result, Response::HTTP_OK);
    }

    public function getMyParticipations(Request $r): JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $person = (new PersonGateway())->of($userId, \PKP\facades\Locale::getLocale());
        $items = ServiceFactory::participants()->myParticipations(
            $this->api->contextInfo(),
            $userId,
            $person['email'] ?? '',
            \PKP\facades\Locale::getLocale(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        return response()->json(['items' => $items, 'itemsMax' => count($items)], Response::HTTP_OK);
    }

    public function cancelMyParticipation(Request $r): JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $person = (new PersonGateway())->of($userId, \PKP\facades\Locale::getLocale());
        $out = ServiceFactory::participants()->cancel(
            $this->api->contextInfo(),
            (int) $r->route('participationId'),
            $userId,
            $person['email'] ?? '',
            false,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->api->remoteAddr(),
        );
        return ParticipantRoutes::cancelResponse($out);
    }

    public function getMyInvoices(Request $r): JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $locale = \PKP\facades\Locale::getLocale();
        $person = (new PersonGateway())->of($userId, $locale);
        $context = $this->api->contextInfo();
        $items = ServiceFactory::proofs()->withProofs($context, ServiceFactory::invoices()->mine($context, $userId, $person['email'] ?? '', $locale), $locale);
        return response()->json(['items' => $items, 'itemsMax' => count($items)], Response::HTTP_OK);
    }

    public function getMyInvoicePdf(Request $r): Response|JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $person = (new PersonGateway())->of($userId, \PKP\facades\Locale::getLocale());
        return InvoiceRoutes::pdfResponse((int) $r->route('invoiceId'), $this->api, [$userId, $person['email'] ?? '']);
    }

    public function addMyProof(Request $r): JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $locale = \PKP\facades\Locale::getLocale();
        $person = (new PersonGateway())->of($userId, $locale);
        $file = $r->file('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $errors = new ValidationErrors();
            $errors->add('file', ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true))
                ? 'plugins.generic.conferenceManager.proof.error.tooLarge'
                : 'plugins.generic.conferenceManager.proof.error.empty');
            return ConferenceApiController::errorResponse($errors, Response::HTTP_BAD_REQUEST);
        }
        $out = ServiceFactory::proofs()->upload(
            $this->api->contextInfo(),
            (int) $r->route('invoiceId'),
            $userId,
            $person['email'] ?? '',
            (string) $file->getRealPath(),
            (string) $file->getClientOriginalName(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $locale,
        );
        if ($out === null) {
            return ConferenceApiController::forbidden();
        }
        [$proof, $errors, $warnings] = $out;
        if ($proof === null) {
            return ConferenceApiController::errorResponse($errors, $errors->has('status') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST);
        }
        return response()->json($proof + ['warnings' => $warnings], Response::HTTP_OK);
    }

    public function getMyProofFile(Request $r): Response|JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $person = (new PersonGateway())->of($userId, \PKP\facades\Locale::getLocale());
        return InvoiceRoutes::proofFileResponse($this->api, (int) $r->route('proofId'), [$userId, $person['email'] ?? '']);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }
}
