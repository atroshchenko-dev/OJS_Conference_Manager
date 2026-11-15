<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PricingMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Tariff;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffQuery;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Participant categories and tariffs of an edition (FEE-01…03):
 *
 * GET    editions/{id}/fees                         categories, tariffs, choices
 * POST   editions/{id}/fees/categories              add a category
 * PUT    editions/{id}/fees/categories/{cid}        change it
 * DELETE editions/{id}/fees/categories/{cid}        delete (409 while participants have it)
 * POST   editions/{id}/fees/presets                 add the preset categories {institutionType}
 * POST   editions/{id}/fees/tariffs                 add a tariff
 * PUT    editions/{id}/fees/tariffs/{tid}           change it
 * DELETE editions/{id}/fees/tariffs/{tid}           delete
 * GET    editions/{id}/fees/quote?categoryId&material&pages&presentationType&at   calculate
 */
final class FeeRoutes implements RouteModule
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
        $base = 'editions/{editionId}/fees';
        Route::get($base, $this->getFees(...))->name('conference.fees.get')->whereNumber('editionId');
        Route::post($base . '/categories', $this->addFeeCategory(...))->name('conference.fees.categories.add')->whereNumber('editionId');
        Route::put($base . '/categories/{categoryId}', $this->editFeeCategory(...))->name('conference.fees.categories.edit')->whereNumber(['editionId', 'categoryId']);
        Route::delete($base . '/categories/{categoryId}', $this->deleteFeeCategory(...))->name('conference.fees.categories.delete')->whereNumber(['editionId', 'categoryId']);
        Route::post($base . '/presets', $this->addFeePresets(...))->name('conference.fees.presets')->whereNumber('editionId');
        Route::post($base . '/tariffs', $this->addTariff(...))->name('conference.fees.tariffs.add')->whereNumber('editionId');
        Route::put($base . '/tariffs/{tariffId}', $this->editTariff(...))->name('conference.fees.tariffs.edit')->whereNumber(['editionId', 'tariffId']);
        Route::delete($base . '/tariffs/{tariffId}', $this->deleteTariff(...))->name('conference.fees.tariffs.delete')->whereNumber(['editionId', 'tariffId']);
        Route::get($base . '/quote', $this->quoteFee(...))->name('conference.fees.quote')->whereNumber('editionId');
    }

    public function getFees(Request $r): JsonResponse
    {
        $context = $this->api->contextInfo();
        $id = (int) $r->route('editionId');
        $service = ServiceFactory::fees();
        $edition = $service->edition($context, $id);
        if ($edition === null) {
            return ConferenceApiController::notFound();
        }
        $choices = static fn (array $cases): array => array_map(
            static fn (\BackedEnum $c): array => ['value' => $c->value, 'label' => __($c->labelKey())], // @phpstan-ignore method.notFound
            $cases,
        );
        return response()->json([
            'timezone' => $edition->timezone,
            'categories' => array_map(static fn (FeeCategory $c): array => $c->toArray(), $service->categories($context, $id) ?? []),
            'tariffs' => array_map(static fn (Tariff $t): array => $service->present($t, $edition), $service->tariffs($context, $id) ?? []),
            'options' => [
                'materials' => $choices(MaterialType::cases()),
                'pricing' => $choices(PricingMode::cases()),
                'presentationTypes' => $choices(PresentationType::cases()),
                'institutionTypes' => $choices(InstitutionType::cases()),
                'institutionType' => ServiceFactory::institutionProfiles()->getOrDraft($context)->type->value,
                'currencies' => ['UAH', 'USD', 'EUR'],
            ],
        ], Response::HTTP_OK);
    }

    public function addFeeCategory(Request $r): JsonResponse
    {
        return $this->categoryResult(ServiceFactory::fees()->saveCategory($this->api->contextInfo(), (int) $r->route('editionId'), null, (array) $r->input(), $this->api->actorId()));
    }

    public function editFeeCategory(Request $r): JsonResponse
    {
        return $this->categoryResult(ServiceFactory::fees()->saveCategory($this->api->contextInfo(), (int) $r->route('editionId'), (int) $r->route('categoryId'), (array) $r->input(), $this->api->actorId()));
    }

    public function deleteFeeCategory(Request $r): JsonResponse
    {
        $errors = ServiceFactory::fees()->deleteCategory($this->api->contextInfo(), (int) $r->route('editionId'), (int) $r->route('categoryId'), $this->api->actorId());
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty()
            ? response()->json(['id' => (int) $r->route('categoryId')], Response::HTTP_OK)
            : ConferenceApiController::errorResponse($errors, Response::HTTP_CONFLICT);
    }

    public function addFeePresets(Request $r): JsonResponse
    {
        $type = InstitutionType::tryFrom((string) $r->input('institutionType', ''))
            ?? ServiceFactory::institutionProfiles()->getOrDraft($this->api->contextInfo())->type;
        $categories = ServiceFactory::fees()->applyPreset(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            $type,
            static fn (string $key, string $locale): string => __($key, [], $locale),
            $this->api->actorId(),
        );
        return $categories === null
            ? ConferenceApiController::notFound()
            : response()->json(['categories' => array_map(static fn (FeeCategory $c): array => $c->toArray(), $categories)], Response::HTTP_OK);
    }

    public function addTariff(Request $r): JsonResponse
    {
        return $this->tariffResult((int) $r->route('editionId'), null, (array) $r->input());
    }

    public function editTariff(Request $r): JsonResponse
    {
        return $this->tariffResult((int) $r->route('editionId'), (int) $r->route('tariffId'), (array) $r->input());
    }

    public function deleteTariff(Request $r): JsonResponse
    {
        $id = (int) $r->route('tariffId');
        return ServiceFactory::fees()->deleteTariff($this->api->contextInfo(), (int) $r->route('editionId'), $id, $this->api->actorId())
            ? response()->json(['id' => $id], Response::HTTP_OK)
            : ConferenceApiController::notFound();
    }

    public function quoteFee(Request $r): JsonResponse
    {
        $context = $this->api->contextInfo();
        $id = (int) $r->route('editionId');
        $service = ServiceFactory::fees();
        $edition = $service->edition($context, $id);
        $material = MaterialType::tryFrom((string) $r->query('material', ''));
        if ($edition === null || $material === null) {
            return ConferenceApiController::notFound();
        }
        $at = DateInput::parse($r->query('at'), $edition->timezone);
        $pages = $r->query('pages');
        $quote = $service->quote($context, $id, new TariffQuery(
            (int) $r->query('categoryId', '0'),
            $material,
            $at instanceof DateTimeImmutable ? $at : new DateTimeImmutable('now', new DateTimeZone('UTC')),
            is_numeric($pages) ? (int) $pages : null,
            PresentationType::tryFrom((string) $r->query('presentationType', '')),
        ));
        return $quote === null ? ConferenceApiController::notFound() : response()->json($quote->toArray(), Response::HTTP_OK);
    }

    /**
     * @param array{0: ?FeeCategory, 1: \APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors}|null $result
     */
    private function categoryResult(?array $result): JsonResponse
    {
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        [$saved, $errors] = $result;
        return $saved === null ? ConferenceApiController::errorResponse($errors) : response()->json($saved->toArray(), Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tariffResult(int $editionId, ?int $id, array $input): JsonResponse
    {
        $context = $this->api->contextInfo();
        $service = ServiceFactory::fees();
        $result = $service->saveTariff($context, $editionId, $id, $input, $this->api->actorId());
        $edition = $service->edition($context, $editionId);
        if ($result === null || $edition === null) {
            return ConferenceApiController::notFound();
        }
        [$saved, $errors] = $result;
        return $saved === null ? ConferenceApiController::errorResponse($errors) : response()->json($service->present($saved, $edition), Response::HTTP_OK);
    }
}
