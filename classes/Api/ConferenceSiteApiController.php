<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\PublicAccessPolicy;

/**
 * /index/api/v1/conference-site/upcoming (EDN-08, public).
 * Registered only when the site administrator enabled the plugin
 * at the site level (Administration → Plugins).
 *
 * GET upcoming  ?journal=&type=&format=&q=&count=&offset=
 */
class ConferenceSiteApiController extends PKPBaseController
{
    public function getHandlerPath(): string
    {
        return 'conference-site';
    }

    public function isSiteWide(): bool
    {
        return true;
    }

    public function getRouteGroupMiddleware(): array
    {
        return [];
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new PublicAccessPolicy());
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function getGroupRoutes(): void
    {
        Route::get('upcoming', $this->upcoming(...))->name('conference-site.upcoming');
    }

    public function upcoming(Request $illuminateRequest): JsonResponse
    {
        $query = ServiceFactory::upcoming();
        $result = $query->find([
            'contextId' => (int) $illuminateRequest->query('journal', '0') ?: null,
            'eventType' => EventType::tryFrom((string) $illuminateRequest->query('type', ''))?->value,
            'format' => EventFormat::tryFrom((string) $illuminateRequest->query('format', ''))?->value,
            'q' => mb_substr(trim((string) $illuminateRequest->query('q', '')), 0, 100),
        ], (int) $illuminateRequest->query('count', '20'), (int) $illuminateRequest->query('offset', '0'));

        $request = $this->getRequest();
        $dispatcher = $request->getDispatcher();
        $contexts = [];
        $items = [];
        foreach ($result['items'] as $edition) {
            $contexts[$edition->contextId] ??= Application::getContextDAO()->getById($edition->contextId);
            $context = $contexts[$edition->contextId];
            if ($context === null) {
                continue;
            }
            $data = $edition->toArray();
            // Public subset: no internal ids of OJS objects.
            unset($data['issueId'], $data['vectorProgramId']);
            $items[] = $data + [
                'journal' => ['id' => (int) $context->getId(), 'path' => $context->getPath(), 'name' => $context->getName(null)],
                'url' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'conference', $edition->path),
            ];
        }
        return response()->json(['items' => $items, 'itemsMax' => $result['itemsMax']], Response::HTTP_OK);
    }
}
