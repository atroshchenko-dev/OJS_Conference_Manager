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
use PKP\security\Role;

/**
 * DEC-10, for managers of the journal:
 *
 * GET  editions/{id}/bids            bids, reviewers, the proposed distribution
 * PUT  editions/{id}/bids/settings   {biddingUntil: Y-m-d|null, reviewersPerSubmission}
 * POST editions/{id}/bids/assign     {pairs: [{submissionId, userId}]} → {assigned, skipped}
 * POST editions/{id}/bids/invite     letters to every reviewer of the journal → {sent}
 */
final class BiddingRoutes implements RouteModule
{
    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER];
    }

    public function routes(): void
    {
        Route::get('editions/{editionId}/bids', $this->overview(...))->name('conference.bids')->whereNumber('editionId');
        Route::put('editions/{editionId}/bids/settings', $this->settings(...))->name('conference.bids.settings')->whereNumber('editionId');
        Route::post('editions/{editionId}/bids/assign', $this->assign(...))->name('conference.bids.assign')->whereNumber('editionId');
        Route::post('editions/{editionId}/bids/invite', $this->invite(...))->name('conference.bids.invite')->whereNumber('editionId');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function allowed(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    /** @return array<string, mixed>|null */
    private function listing(int $editionId): ?array
    {
        return ServiceFactory::reviewBidding()->overview($this->api->contextInfo(), $editionId, \PKP\facades\Locale::getLocale(), self::now());
    }

    public function overview(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $out = $this->listing((int) $r->route('editionId'));
        return $out === null ? ConferenceApiController::notFound() : response()->json($out, Response::HTTP_OK);
    }

    public function settings(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $input = array_intersect_key((array) $r->input(), ['biddingUntil' => true, 'reviewersPerSubmission' => true]);
        $out = ServiceFactory::reviewCommittee()->saveSettings($this->api->contextInfo(), $editionId, $input, $this->api->actorId());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$saved, $errors] = $out;
        return $saved === null ? ConferenceApiController::errorResponse($errors) : response()->json($this->listing($editionId), Response::HTTP_OK);
    }

    public function assign(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $out = ServiceFactory::reviewBidding()->assign($this->api->contextInfo(), $editionId, array_values((array) $r->input('pairs', [])), (int) $this->api->actorId(), \PKP\facades\Locale::getLocale());
        return $out === null ? ConferenceApiController::notFound() : response()->json($out + (array) $this->listing($editionId), Response::HTTP_OK);
    }

    public function invite(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::reviewBidding()->invite($this->api->contextInfo(), (int) $r->route('editionId'), (int) $this->api->actorId(), \PKP\facades\Locale::getLocale(), self::now());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$sent, $errors] = $out;
        return $sent === null ? ConferenceApiController::errorResponse($errors) : response()->json(['sent' => $sent], Response::HTTP_OK);
    }
}
