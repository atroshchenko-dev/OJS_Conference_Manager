<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * The book of abstracts of an edition (PUB-04), journal manager:
 *
 * GET editions/{id}/abstract-book          settings, papers by groups, papers without an abstract
 * PUT editions/{id}/abstract-book          settings
 * GET editions/{id}/abstract-book/pdf      the book
 */
final class BookRoutes implements RouteModule
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
        $base = 'editions/{editionId}/abstract-book';
        Route::get($base, $this->overview(...))->name('conference.book.get')->whereNumber('editionId');
        Route::put($base, $this->save(...))->name('conference.book.save')->whereNumber('editionId');
        Route::get($base . '/pdf', $this->pdf(...))->name('conference.book.pdf')->whereNumber('editionId');
    }

    private function allowed(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    public function overview(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $data = ServiceFactory::abstractBook()->overview($this->api->contextInfo(), (int) $r->route('editionId'), \PKP\facades\Locale::getLocale());
        return $data === null ? ConferenceApiController::notFound() : response()->json($data, Response::HTTP_OK);
    }

    public function save(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::abstractBook();
        $context = $this->api->contextInfo();
        $id = (int) $r->route('editionId');
        $result = $service->saveSettings($context, $id, (array) $r->input(), $this->api->actorId());
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        [$book, $errors] = $result;
        if ($book === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json($service->overview($context, $id, \PKP\facades\Locale::getLocale()), Response::HTTP_OK);
    }

    public function pdf(Request $r): Response|JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $pdf = ServiceFactory::abstractBook()->pdf($this->api->contextInfo(), (int) $r->route('editionId'), \PKP\facades\Locale::getLocale());
        if ($pdf === null) {
            return ConferenceApiController::notFound();
        }
        return new Response($pdf['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf['name'] . '"',
            'Content-Length' => (string) strlen($pdf['content']),
            'Cache-Control' => 'no-store',
        ]);
    }
}
