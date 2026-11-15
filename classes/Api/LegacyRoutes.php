<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Old OCS addresses of the journal (LEG-02, LEG-03), journal manager only.
 *
 * GET    legacy-urls                      templates and how many papers are matched
 * PUT    legacy-urls/templates            {templates: [..]}; [] restores the defaults
 * POST   legacy-urls/import               {csv} → report
 * DELETE legacy-urls                      forgets all papers of the journal
 * GET    legacy-urls/check?url=           {ocsPaperId, submissionId, target} or 404
 * GET    legacy-urls/map?server=          nginx | apache-map | apache-rules (text file)
 */
final class LegacyRoutes implements RouteModule
{
    private const K = 'plugins.generic.conferenceManager.legacy.';

    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER];
    }

    public function routes(): void
    {
        Route::get('legacy-urls', $this->overview(...))->name('conference.legacy.get');
        Route::put('legacy-urls/templates', $this->saveTemplates(...))->name('conference.legacy.templates');
        Route::post('legacy-urls/import', $this->import(...))->name('conference.legacy.import');
        Route::delete('legacy-urls', $this->clear(...))->name('conference.legacy.clear');
        Route::get('legacy-urls/check', $this->check(...))->name('conference.legacy.check');
        Route::get('legacy-urls/map', $this->map(...))->name('conference.legacy.map');
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
        return response()->json(ServiceFactory::legacyUrls()->overview($this->api->contextInfo()), Response::HTTP_OK);
    }

    public function saveTemplates(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::legacyUrls();
        $bad = $service->savePatterns($this->api->contextInfo(), $r->input('templates', []), $this->api->actorId());
        if ($bad !== []) {
            $messages = [];
            foreach ($bad as $line => $key) {
                $messages[] = __(self::K . 'line', ['n' => $line]) . ' ' . __($key);
            }
            return response()->json(['templates' => $messages], Response::HTTP_BAD_REQUEST);
        }
        return response()->json($service->overview($this->api->contextInfo()), Response::HTTP_OK);
    }

    public function import(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $report = ServiceFactory::legacyUrls()->import($this->api->contextInfo(), (string) $r->input('csv', ''), $this->api->actorId());
        $report['errors'] = array_map(static fn (array $e): array => $e + ['text' => ($e['line'] > 0 ? __(self::K . 'line', ['n' => $e['line']]) . ' ' : '') . __($e['key'], ['path' => $e['value'] ?? ''])], $report['errors']);
        return response()->json($report, Response::HTTP_OK);
    }

    public function clear(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(['deleted' => ServiceFactory::legacyUrls()->clear($this->api->contextInfo(), $this->api->actorId())], Response::HTTP_OK);
    }

    public function check(Request $r): JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $found = ServiceFactory::legacyUrls()->resolve($this->api->contextInfo(), mb_substr((string) $r->query('url', ''), 0, 2000));
        if ($found === null) {
            return ConferenceApiController::notFound();
        }
        return response()->json($found + ['target' => $found['submissionId'] === null ? null : $this->articleUrl()($found['submissionId'])], Response::HTTP_OK);
    }

    public function map(Request $r): Response|JsonResponse
    {
        if (!$this->allowed()) {
            return ConferenceApiController::forbidden();
        }
        $server = (string) $r->query('server', 'nginx');
        $context = $this->api->contextInfo();
        $service = ServiceFactory::legacyUrls();
        $url = $this->articleUrl();
        $papers = array_map(static fn (array $p): array => $p + ['target' => $url($p['submissionId'])], $service->mapPapers($context));
        $path = (string) $this->api->getRequest()->getContext()?->getPath();
        $comment = 'conferenceManager: old OCS addresses of "' . $path . '", ' . count($papers) . ' papers, ' . gmdate('Y-m-d H:i') . ' UTC';
        $templates = $service->templates($context);
        $mapFile = '/etc/apache2/conference-legacy-' . $path . '.txt';
        [$text, $name] = match ($server) {
            'apache-map' => [LegacyMap::apache($templates, $papers, $comment, $mapFile)['map'], 'conference-legacy-' . $path . '.txt'],
            'apache-rules' => [LegacyMap::apache($templates, $papers, $comment, $mapFile)['rules'], 'conference-legacy-' . $path . '.conf'],
            default => [LegacyMap::nginx($templates, $papers, $comment), 'conference-legacy-' . $path . '.nginx.conf'],
        };
        return new Response($text, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return \Closure(int): string the address of an article page, for any id */
    private function articleUrl(): \Closure
    {
        $request = $this->api->getRequest();
        $template = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, (string) $request->getContext()?->getPath(), 'article', 'view', ['987654321']);
        return static fn (int $id): string => str_replace('987654321', (string) $id, $template);
    }
}
