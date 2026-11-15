<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * /{context}/api/v1/conference/institution
 *
 * GET    institution          profile (or a draft prefilled from the context)
 * PUT    institution          save the whole profile
 * GET    institution/export   portable JSON (INS-07)
 * POST   institution/import   import portable JSON
 * GET    jurisdictions        available jurisdiction profiles and their fields
 */
final class InstitutionRoutes implements RouteModule
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
        Route::get('institution', $this->get(...))->name('conference.institution.get');
        Route::put('institution', $this->put(...))->name('conference.institution.put');
        Route::get('institution/export', $this->export(...))->name('conference.institution.export');
        Route::post('institution/import', $this->import(...))->name('conference.institution.import');
        Route::get('jurisdictions', $this->jurisdictions(...))->name('conference.jurisdictions');
    }

    public function get(Request $illuminateRequest): JsonResponse
    {
        $context = $this->api->contextInfo();
        $profile = ServiceFactory::institutionProfiles()->getOrDraft($context);
        return response()->json($profile->toArray(), Response::HTTP_OK);
    }

    public function put(Request $illuminateRequest): JsonResponse
    {
        $context = $this->api->contextInfo();
        $service = ServiceFactory::institutionProfiles();
        $input = $illuminateRequest->input();
        // Files are uploaded separately; the JSON body cannot change them.
        $current = $service->getOrDraft($context);
        $input['logoPath'] = $current->logoPath;
        $input['sealPath'] = $current->sealPath;

        [$saved, $errors] = $service->save($context, $input, $this->api->actorId(), $this->api->remoteAddr());
        if ($saved === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json($saved->toArray(), Response::HTTP_OK);
    }

    public function export(Request $illuminateRequest): JsonResponse
    {
        $request = $this->api->getRequest();
        $context = $this->api->contextInfo();
        $data = ServiceFactory::institutionProfiles()->export($context);
        $filename = 'institution-' . $request->getContext()->getPath() . '.json';
        return response()
            ->json($data, Response::HTTP_OK, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function import(Request $illuminateRequest): JsonResponse
    {
        $context = $this->api->contextInfo();
        $json = (string) $illuminateRequest->getContent();
        [$saved, $errors] = ServiceFactory::institutionProfiles()
            ->import($context, $json, $this->api->actorId(), $this->api->remoteAddr());
        if ($saved === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        return response()->json($saved->toArray(), Response::HTTP_OK);
    }

    public function jurisdictions(Request $illuminateRequest): JsonResponse
    {
        $items = array_map(static fn (JurisdictionProfile $p): array => [
            'code' => $p->getCode(),
            'name' => __($p->getNameKey()),
            'legalIdLabel' => __($p->getLegalIdLabelKey()),
            'features' => $p->getFeatures(),
            'defaults' => $p->getDefaults(),
            'accountKinds' => array_map(static fn ($k) => $k->value, $p->allowedAccountKinds()),
            'institutionFields' => array_map(static fn ($f): array => [
                'name' => $f->name,
                'label' => __($f->labelKey),
                'type' => $f->type,
                'required' => $f->required,
                'maxLength' => $f->maxLength,
            ], $p->getInstitutionExtraFields()),
        ], array_values(ServiceFactory::jurisdictions()->all()));
        return response()->json(['items' => $items, 'itemsMax' => count($items)], Response::HTTP_OK);
    }
}
