<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\SubmissionAccessPolicy;
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
        $this->modules = $modules ?? [new InstitutionRoutes($this), new EditionRoutes($this), new FeeRoutes($this), new RegistrationRoutes($this), new ParticipantRoutes($this), new InvoiceRoutes($this), new MyParticipationRoutes($this), new DecisionRoutes($this), new CommunicationRoutes($this)];
    }

    public function getHandlerPath(): string
    {
        return 'conference';
    }

    public function getRouteGroupMiddleware(): array
    {
        // Roles are set per module in getGroupRoutes().
        return ['has.user', 'has.context'];
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $action = static::getRouteActionName($args[0] ?? null);
        if (in_array($action, [...RegistrationRoutes::SUBMISSION_ACTIONS, ...DecisionRoutes::SUBMISSION_ACTIONS], true)) {
            // The author of the submission or an editor with access to it.
            $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
        }
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function getGroupRoutes(): void
    {
        foreach ($this->modules as $module) {
            Route::middleware([self::roleAuthorizer($module->roles())])->group(static function () use ($module): void {
                $module->routes();
            });
        }
    }

    /** @return \APP\submission\Submission|null the submission authorized by SubmissionAccessPolicy */
    public function authorizedSubmission(): mixed
    {
        return $this->getAuthorizedContextObject(\APP\core\Application::ASSOC_TYPE_SUBMISSION);
    }

    public function userHasRole(array $roles): bool
    {
        $request = $this->getRequest();
        $user = $request->getUser();
        $contextId = $request->getContext()?->getId();
        return $user !== null && (
            $user->hasRole($roles, (int) $contextId)
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], \APP\core\Application::SITE_CONTEXT_ID)
        );
    }

    public function canManageRegistrations(): bool
    {
        $actor = $this->actorId();
        $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'conferencemanagerplugin');
        return $actor !== null && $plugin instanceof \PKP\plugins\Plugin
            && (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($plugin))->canManageRegistrations($this->contextInfo()->id, $actor);
    }

    public static function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'api.403.unauthorized', 'errorMessage' => __('api.403.unauthorized')], Response::HTTP_FORBIDDEN);
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
