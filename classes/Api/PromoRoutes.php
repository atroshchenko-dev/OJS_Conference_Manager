<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoCode;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Seats and promo codes of an edition, for managers and registration managers:
 *
 * GET    editions/{id}/promo-codes          codes with their uses, seats, the waiting list
 * POST   editions/{id}/promo-codes          a new code (REG-11)
 * PUT    editions/{id}/promo-codes/{cid}    changes; a used code keeps its terms
 * DELETE editions/{id}/promo-codes/{cid}    only a code nobody used
 * PUT    editions/{id}/seats                {seats: number|null} in-person listener seats (REG-12)
 */
final class PromoRoutes implements RouteModule
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
        $base = 'editions/{editionId}/promo-codes';
        Route::get($base, $this->list(...))->name('conference.promo.list')->whereNumber('editionId');
        Route::post($base, $this->create(...))->name('conference.promo.create')->whereNumber('editionId');
        Route::put($base . '/{codeId}', $this->update(...))->name('conference.promo.update')->whereNumber(['editionId', 'codeId']);
        Route::delete($base . '/{codeId}', $this->delete(...))->name('conference.promo.delete')->whereNumber(['editionId', 'codeId']);
        Route::put('editions/{editionId}/seats', $this->seats(...))->name('conference.seats.set')->whereNumber('editionId');
    }

    private function allowed(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]) || $this->api->canManageRegistrations();
    }

    public function list(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $out = $this->listing((int) $r->route('editionId'));
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function create(Request $r): JsonResponse
    {
        return $this->save($r, null);
    }

    public function update(Request $r): JsonResponse
    {
        return $this->save($r, (int) $r->route('codeId'));
    }

    public function delete(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $done = ServiceFactory::promoCodes()->delete($this->api->contextInfo(), $editionId, (int) $r->route('codeId'), $this->api->actorId());
        if ($done === null) {
            return ConferenceApiController::notFound();
        }
        if ($done === false) {
            $errors = new ValidationErrors();
            $errors->add('code', 'plugins.generic.conferenceManager.promo.error.used');
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json($this->listing($editionId), Response::HTTP_OK);
    }

    public function seats(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        [$given, $errors] = ServiceFactory::participants()->setSeats(
            $this->api->contextInfo(),
            $editionId,
            $r->input('seats'),
            $this->api->actorId(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        if ($given === null) {
            return $errors->has('editionId') ? ConferenceApiController::notFound() : ConferenceApiController::errorResponse($errors);
        }
        return response()->json(['given' => $given] + (array) $this->listing($editionId), Response::HTTP_OK);
    }

    private function save(Request $r, ?int $id): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $out = ServiceFactory::promoCodes()->save($this->api->contextInfo(), $editionId, $id, (array) $r->input(), $this->api->actorId());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$code, $errors] = $out;
        if ($code === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json(['saved' => $code->id] + (array) $this->listing($editionId), $id === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** @return array<string, mixed>|null */
    private function listing(int $editionId): ?array
    {
        $context = $this->api->contextInfo();
        $codes = ServiceFactory::promoCodes()->ofEdition($context, $editionId);
        $seats = ServiceFactory::participants()->seats($context, $editionId, \PKP\facades\Locale::getLocale());
        if ($codes === null || $seats === null) {
            return null;
        }
        $currencies = [];
        foreach (ServiceFactory::fees()->tariffs($context, $editionId) ?? [] as $t) {
            $currencies[$t->amount->currency] = true;
        }
        return [
            'codes' => array_map(static fn (PromoCode $c): array => $c->toArray(), $codes),
            'currencies' => array_keys($currencies) ?: ['UAH'],
            'max' => PromoCode::MAX_PER_EDITION,
        ] + $seats;
    }
}
