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
 * PAY-09, for managers of the journal:
 *
 * GET  payment-gateway                  the card payment setup (secrets only as "set")
 * PUT  payment-gateway                  {gateway: ''|id, sandbox, values: {…}}; an empty secret keeps the stored one
 * GET  invoices/{id}/gateway-payments   card payment attempts of an invoice
 */
final class GatewayRoutes implements RouteModule
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
        Route::get('payment-gateway', $this->get(...))->name('conference.gateway.get');
        Route::put('payment-gateway', $this->put(...))->name('conference.gateway.edit');
        Route::get('invoices/{invoiceId}/gateway-payments', $this->attempts(...))->name('conference.gateway.attempts')->whereNumber('invoiceId');
    }

    private function allowed(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    public function get(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(ServiceFactory::onlinePayments()->settings($this->api->contextInfo()), Response::HTTP_OK);
    }

    public function put(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::onlinePayments();
        $context = $this->api->contextInfo();
        $errors = $service->saveSettings($context, (array) $r->input(), $this->api->actorId());
        return $errors->isEmpty()
            ? response()->json($service->settings($context), Response::HTTP_OK)
            : ConferenceApiController::errorResponse($errors);
    }

    public function attempts(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $id = (int) $r->route('invoiceId');
        if (ServiceFactory::invoices()->find($context, $id) === null) {
            return ConferenceApiController::notFound();
        }
        return response()->json(['items' => ServiceFactory::onlinePayments()->attempts($context, $id)], Response::HTTP_OK);
    }
}
