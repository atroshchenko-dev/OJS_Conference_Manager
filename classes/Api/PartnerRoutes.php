<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Co-organisers and partners of an edition (INS-04), journal manager:
 *
 * GET    editions/{id}/partners                 the list, roles and the "partner" payment accounts
 * POST   editions/{id}/partners                 a new partner
 * PUT    editions/{id}/partners/{pid}           changes
 * DELETE editions/{id}/partners/{pid}
 * POST   editions/{id}/partners/{pid}/move      {direction: -1|1}
 * POST   editions/{id}/partners/{pid}/logo      multipart "logo" (PNG, JPEG, WebP up to 1 MB)
 * DELETE editions/{id}/partners/{pid}/logo
 */
final class PartnerRoutes implements RouteModule
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
        $base = 'editions/{editionId}/partners';
        $one = $base . '/{partnerId}';
        Route::get($base, $this->list(...))->name('conference.partners.list')->whereNumber('editionId');
        Route::post($base, $this->create(...))->name('conference.partners.create')->whereNumber('editionId');
        Route::put($one, $this->update(...))->name('conference.partners.update')->whereNumber(['editionId', 'partnerId']);
        Route::delete($one, $this->delete(...))->name('conference.partners.delete')->whereNumber(['editionId', 'partnerId']);
        Route::post($one . '/move', $this->move(...))->name('conference.partners.move')->whereNumber(['editionId', 'partnerId']);
        Route::post($one . '/logo', $this->setLogo(...))->name('conference.partners.logo')->whereNumber(['editionId', 'partnerId']);
        Route::delete($one . '/logo', $this->removeLogo(...))->name('conference.partners.logoDelete')->whereNumber(['editionId', 'partnerId']);
    }

    private function allowed(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
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
        return $this->save($r, (int) $r->route('partnerId'));
    }

    public function delete(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        if (!ServiceFactory::partners()->delete($this->api->contextInfo(), $editionId, (int) $r->route('partnerId'), $this->api->actorId())) {
            return ConferenceApiController::notFound();
        }
        return response()->json($this->listing($editionId), Response::HTTP_OK);
    }

    public function move(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $moved = ServiceFactory::partners()->move($this->api->contextInfo(), $editionId, (int) $r->route('partnerId'), (int) $r->input('direction', 1));
        return $moved === null ? ConferenceApiController::notFound() : response()->json($this->listing($editionId), Response::HTTP_OK);
    }

    public function setLogo(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $file = $r->file('logo');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $errors = new ValidationErrors();
            $errors->add('logo', ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true))
                ? 'plugins.generic.conferenceManager.partner.error.logoTooLarge'
                : 'plugins.generic.conferenceManager.partner.error.logoEmpty');
            return ConferenceApiController::errorResponse($errors);
        }
        $editionId = (int) $r->route('editionId');
        $out = ServiceFactory::partners()->setLogo($this->api->contextInfo(), $editionId, (int) $r->route('partnerId'), (string) $file->getRealPath(), $this->api->actorId());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$partner, $errors] = $out;
        return $partner === null ? ConferenceApiController::errorResponse($errors) : response()->json($this->listing($editionId), Response::HTTP_OK);
    }

    public function removeLogo(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $partner = ServiceFactory::partners()->removeLogo($this->api->contextInfo(), $editionId, (int) $r->route('partnerId'), $this->api->actorId());
        return $partner === null ? ConferenceApiController::notFound() : response()->json($this->listing($editionId), Response::HTTP_OK);
    }

    private function save(Request $r, ?int $id): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $editionId = (int) $r->route('editionId');
        $out = ServiceFactory::partners()->save($this->api->contextInfo(), $editionId, $id, (array) $r->input(), $this->api->actorId());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$partner, $errors] = $out;
        if ($partner === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json(['saved' => $partner->id] + (array) $this->listing($editionId), $id === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** @return array<string, mixed>|null */
    private function listing(int $editionId): ?array
    {
        $service = ServiceFactory::partners();
        $context = $this->api->contextInfo();
        $list = $service->ofEdition($context, $editionId);
        if ($list === null) {
            return null;
        }
        $locale = \PKP\facades\Locale::getLocale();
        $logoUrl = ServiceFactory::partnerLogoUrl();
        $accounts = [];
        foreach (ServiceFactory::institutionProfiles()->getOrDraft($context)->activePaymentAccounts() as $a) {
            if ($a->kind === PaymentAccountKind::Partner) {
                $accounts[] = ['id' => (int) $a->id, 'name' => $a->label !== '' ? $a->label : $a->recipientName];
            }
        }
        return [
            'partners' => array_map(static fn ($p): array => $service->present($context, $p, $logoUrl, $locale), $list),
            'roles' => array_map(static fn (PartnerRole $role): array => ['value' => $role->value, 'label' => __($role->labelKey())], PartnerRole::cases()),
            'accounts' => $accounts,
            'max' => \APP\plugins\generic\conferenceManager\classes\Application\PartnerService::MAX,
        ];
    }
}
