<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PaymentFilter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * The register of participants for managers and registration managers
 * (REG-07, REG-08):
 *
 * GET  editions/{id}/participants?role&categoryId&status&confirmation&searchPhrase&count&offset
 * GET  editions/{id}/participants/export?…the same filters   CSV of all matching rows
 * PUT  participations/{pid}/category {categoryId|null}
 * POST participations/{pid}/cancel                            at any time
 */
final class ParticipantRoutes implements RouteModule
{
    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        // Assistant: the "Registration manager" group; checked once more
        // in every action, because other assistants have this role too.
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_ASSISTANT];
    }

    public function routes(): void
    {
        Route::get('editions/{editionId}/participants', $this->getParticipants(...))->name('conference.participants.get')->whereNumber('editionId');
        Route::get('editions/{editionId}/participants/export', $this->exportParticipants(...))->name('conference.participants.export')->whereNumber('editionId');
        Route::put('participations/{participationId}/category', $this->setParticipantCategory(...))->name('conference.participations.category')->whereNumber('participationId');
        Route::post('participations/{participationId}/cancel', $this->cancelParticipation(...))->name('conference.participations.cancel')->whereNumber('participationId');
    }

    public function getParticipants(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $editionId = (int) $r->route('editionId');
        $service = ServiceFactory::participants();
        $page = $service->register($context, $editionId, self::filter($r), \PKP\facades\Locale::getLocale());
        if ($page === null) {
            return ConferenceApiController::notFound();
        }
        $categories = [];
        foreach (ServiceFactory::fees()->categories($context, $editionId) ?? [] as $c) {
            $categories[] = ['id' => $c->id, 'name' => (string) $c->name->resolve(\PKP\facades\Locale::getLocale(), $context->primaryLocale)];
        }
        return response()->json($page + ['maxPage' => ParticipantFilter::MAX_PAGE, 'categories' => $categories], Response::HTTP_OK);
    }

    public function exportParticipants(Request $r): Response|JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $csv = ServiceFactory::participants()->exportCsv($this->api->contextInfo(), $editionId, self::filter($r), \PKP\facades\Locale::getLocale());
        if ($csv === null) {
            return ConferenceApiController::notFound();
        }
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="participants-' . $editionId . '-' . gmdate('Ymd') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function setParticipantCategory(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $raw = $r->input('categoryId');
        $categoryId = $raw === null || $raw === '' || (int) $raw === 0 ? null : (int) $raw;
        $errors = ServiceFactory::participants()->setCategory($this->api->contextInfo(), (int) $r->route('participationId'), $categoryId, $this->api->actorId());
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        if (!$errors->isEmpty()) {
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json(['categoryId' => $categoryId], Response::HTTP_OK);
    }

    public function cancelParticipation(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::participants()->cancel(
            $this->api->contextInfo(),
            (int) $r->route('participationId'),
            $this->api->actorId(),
            '',
            true,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->api->remoteAddr(),
        );
        return self::cancelResponse($out);
    }

    /**
     * @param array{result: string, invoices?: array{cancelled: int, refundRequested: int}} $out
     */
    public static function cancelResponse(array $out): JsonResponse
    {
        return match ($out['result']) {
            ParticipantService::CANCELLED => response()->json(['status' => ParticipationStatus::Cancelled->value, 'invoices' => $out['invoices'] ?? null], Response::HTTP_OK),
            ParticipantService::FORBIDDEN => ConferenceApiController::forbidden(),
            ParticipantService::NOT_FOUND => ConferenceApiController::notFound(),
            default => response()->json([
                'error' => $out['result'],
                'errorMessage' => __('plugins.generic.conferenceManager.participation.error.' . $out['result']),
            ], Response::HTTP_CONFLICT),
        };
    }

    private static function filter(Request $r): ParticipantFilter
    {
        $category = $r->query('categoryId');
        return new ParticipantFilter(
            ParticipantRole::tryFrom((string) $r->query('role', '')),
            $category === null || $category === '' ? null : (int) $category,
            ParticipationStatus::tryFrom((string) $r->query('status', '')),
            ConfirmationStatus::tryFrom((string) $r->query('confirmation', '')),
            mb_substr(trim((string) $r->query('searchPhrase', '')), 0, 100),
            (int) $r->query('count', '20'),
            (int) $r->query('offset', '0'),
            PaymentFilter::tryFrom((string) $r->query('payment', '')),
            match ((string) $r->query('attended', '')) {
                '1', 'yes' => true,
                '0', 'no' => false,
                default => null,
            },
        );
    }
}
