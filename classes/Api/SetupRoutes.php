<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\CategoryPresets;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * The first setup wizard (INS-08) with presets (DST-07). Journal manager:
 *
 * GET  setup    {done, last, editionCount, institutionType, jurisdiction, accounts, presets, labels}
 * POST setup    {preset, name, path, startsAt, endsAt, cfpOpensAt, abstractDeadline, …,
 *               conferenceSite (default true: PUB-05 mode and the menu item)}
 *               → 201 {edition, warnings[{key, message}], siteMode}
 * GET  siteMode {conferenceSite, menuItem}                        PUB-05
 * PUT  siteMode {conferenceSite?, menuItem?: true} → the same
 */
final class SetupRoutes implements RouteModule
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
        Route::get('setup', $this->getSetup(...))->name('conference.setup.get');
        Route::post('setup', $this->applySetup(...))->name('conference.setup.apply');
        Route::get('siteMode', $this->getSiteMode(...))->name('conference.siteMode.get');
        Route::put('siteMode', $this->editSiteMode(...))->name('conference.siteMode.edit');
    }

    public function getSetup(Request $r): JsonResponse
    {
        $context = $this->api->contextInfo();
        $count = ServiceFactory::editions()->list($context, [], 1)['itemsMax'];
        $labels = static function (array $cases): array {
            $out = [];
            foreach ($cases as $c) {
                $out[$c->value] = __($c->labelKey());
            }
            return $out;
        };
        $categories = [];
        foreach (CategoryPresets::allCodes() as $code) {
            $categories[$code] = __('plugins.generic.conferenceManager.fee.preset.' . $code);
        }
        return response()->json(ServiceFactory::setup()->state($context, $count) + ['labels' => [
            'institutionTypes' => $labels(InstitutionType::cases()),
            'eventTypes' => $labels(EventType::cases()),
            'submissionModes' => $labels(SubmissionMode::cases()),
            'publicationModes' => $labels(PublicationMode::cases()),
            'formats' => $labels(EventFormat::cases()),
            'materials' => $labels(MaterialType::cases()),
            'paymentModes' => $labels(PaymentMode::cases()),
            'categories' => $categories,
        ]], Response::HTTP_OK);
    }

    public function applySetup(Request $r): JsonResponse
    {
        $locale = \PKP\facades\Locale::getLocale();
        [$edition, $errors, $warnings] = ServiceFactory::setup()->apply(
            $this->api->contextInfo(),
            (array) $r->input(),
            static fn (string $key, string $l): string => __($key, [], $l),
            $this->api->actorId(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($edition === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        // PUB-05: a journal set up by the wizard is a conference site, unless the manager unticks it.
        $siteMode = filter_var($r->input('conferenceSite', true), FILTER_VALIDATE_BOOLEAN)
            ? ServiceFactory::siteMode()->save($this->api->contextInfo(), ['conferenceSite' => true, 'menuItem' => true], $this->api->actorId())
            : ServiceFactory::siteMode()->state($this->api->contextInfo());
        return response()->json([
            'edition' => $edition->toArray(),
            'warnings' => array_map(static fn (array $w): array => ['key' => $w['key'], 'message' => __($w['key'], $w['params'], $locale)], $warnings),
            'siteMode' => $siteMode,
        ], Response::HTTP_CREATED);
    }

    public function getSiteMode(Request $r): JsonResponse
    {
        return response()->json(ServiceFactory::siteMode()->state($this->api->contextInfo()), Response::HTTP_OK);
    }

    public function editSiteMode(Request $r): JsonResponse
    {
        $input = array_intersect_key((array) $r->input(), ['conferenceSite' => true, 'menuItem' => true]);
        return response()->json(ServiceFactory::siteMode()->save($this->api->contextInfo(), $input, $this->api->actorId()), Response::HTTP_OK);
    }
}
