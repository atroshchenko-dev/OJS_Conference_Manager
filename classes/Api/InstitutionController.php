<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
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
class InstitutionController extends PKPBaseController
{
    public function getHandlerPath(): string
    {
        return 'conference';
    }

    public function getRouteGroupMiddleware(): array
    {
        return [
            'has.user',
            'has.context',
            self::roleAuthorizer([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]),
        ];
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function getGroupRoutes(): void
    {
        Route::get('institution', $this->get(...))->name('conference.institution.get');
        Route::put('institution', $this->put(...))->name('conference.institution.put');
        Route::get('institution/export', $this->export(...))->name('conference.institution.export');
        Route::post('institution/import', $this->import(...))->name('conference.institution.import');
        Route::get('jurisdictions', $this->jurisdictions(...))->name('conference.jurisdictions');
    }

    public function get(Request $illuminateRequest): JsonResponse
    {
        $context = ContextGateway::fromContext($this->getRequest()->getContext());
        $profile = ServiceFactory::institutionProfiles()->getOrDraft($context);
        return response()->json($profile->toArray(), Response::HTTP_OK);
    }

    public function put(Request $illuminateRequest): JsonResponse
    {
        $request = $this->getRequest();
        $context = ContextGateway::fromContext($request->getContext());
        $service = ServiceFactory::institutionProfiles();
        $input = $illuminateRequest->input();
        // Files are uploaded separately; the JSON body cannot change them.
        $current = $service->getOrDraft($context);
        $input['logoPath'] = $current->logoPath;
        $input['sealPath'] = $current->sealPath;

        [$saved, $errors] = $service->save($context, $input, $request->getUser()?->getId(), $request->getRemoteAddr());
        if ($saved === null) {
            return self::errorResponse($errors);
        }
        return response()->json($saved->toArray(), Response::HTTP_OK);
    }

    public function export(Request $illuminateRequest): JsonResponse
    {
        $request = $this->getRequest();
        $context = ContextGateway::fromContext($request->getContext());
        $data = ServiceFactory::institutionProfiles()->export($context);
        $filename = 'institution-' . $request->getContext()->getPath() . '.json';
        return response()
            ->json($data, Response::HTTP_OK, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function import(Request $illuminateRequest): JsonResponse
    {
        $request = $this->getRequest();
        $context = ContextGateway::fromContext($request->getContext());
        $json = (string) $illuminateRequest->getContent();
        [$saved, $errors] = ServiceFactory::institutionProfiles()
            ->import($context, $json, $request->getUser()?->getId(), $request->getRemoteAddr());
        if ($saved === null) {
            return self::errorResponse($errors);
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

    /**
     * Errors in the PKP format: field => list of translated messages.
     */
    private static function errorResponse(ValidationErrors $errors): JsonResponse
    {
        $out = [];
        foreach ($errors->toArray() as $field => $keys) {
            $out[$field] = array_map(static fn (string $k): string => __($k), $keys);
        }
        return response()->json($out, Response::HTTP_BAD_REQUEST);
    }
}
