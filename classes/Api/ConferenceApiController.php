<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

/**
 * The one API controller of the plugin: /{context}/api/v1/conference/...
 *
 * OJS 3.5 matches plugin controllers by the first path segment and
 * allows one controller per segment, so every module adds its routes
 * here through a RouteModule instead of having its own controller.
 */
class ConferenceApiController extends PKPBaseController
{
    /** @var list<RouteModule> */
    private array $modules;

    /**
     * @param list<RouteModule>|null $modules
     */
    public function __construct(?array $modules = null)
    {
        $this->modules = $modules ?? [new InstitutionRoutes($this), new EditionRoutes($this)];
    }

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
        foreach ($this->modules as $module) {
            $module->routes();
        }
    }

    public function contextInfo(): ContextInfo
    {
        return ContextGateway::fromContext($this->getRequest()->getContext());
    }

    public function actorId(): ?int
    {
        $id = $this->getRequest()->getUser()?->getId();
        return $id === null ? null : (int) $id;
    }

    public function remoteAddr(): ?string
    {
        return $this->getRequest()->getRemoteAddr();
    }

    /**
     * Errors in the PKP format: field => list of translated messages.
     */
    public static function errorResponse(ValidationErrors $errors, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        $out = [];
        foreach ($errors->toArray() as $field => $keys) {
            $out[$field] = array_map(static fn (string $k): string => __($k), $keys);
        }
        return response()->json($out, $status);
    }

    public static function notFound(): JsonResponse
    {
        return response()->json(['error' => 'api.404.resourceNotFound', 'errorMessage' => __('api.404.resourceNotFound')], Response::HTTP_NOT_FOUND);
    }
}
