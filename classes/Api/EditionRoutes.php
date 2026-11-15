<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Application\EditionCopyService;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionWindows;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * /{context}/api/v1/conference/editions (plan 9, EDN-01…05, 09)
 *
 * GET    editions               list (?status=cfp_open,held &count &offset)
 * POST   editions               create
 * GET    editions/options       choices for forms: types, modes, statuses
 * GET    editions/{id}          one edition
 * PUT    editions/{id}          replace
 * DELETE editions/{id}          delete (refused when in use, EDN-04)
 * POST   editions/{id}/copy     copy with a new path and dates (EDN-09)
 */
final class EditionRoutes implements RouteModule
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
        Route::get('editions', $this->list(...))->name('conference.editions.list');
        Route::post('editions', $this->create(...))->name('conference.editions.create');
        Route::get('editions/options', $this->options(...))->name('conference.editions.options');
        Route::get('editions/{editionId}', $this->get(...))->name('conference.editions.get')->whereNumber('editionId');
        Route::put('editions/{editionId}', $this->update(...))->name('conference.editions.update')->whereNumber('editionId');
        Route::delete('editions/{editionId}', $this->delete(...))->name('conference.editions.delete')->whereNumber('editionId');
        Route::post('editions/{editionId}/copy', $this->copy(...))->name('conference.editions.copy')->whereNumber('editionId');
    }

    public function list(Request $illuminateRequest): JsonResponse
    {
        $statuses = [];
        foreach (array_filter(explode(',', (string) $illuminateRequest->query('status', ''))) as $raw) {
            $status = EditionStatus::tryFrom(trim($raw));
            if ($status !== null) {
                $statuses[] = $status;
            }
        }
        $result = ServiceFactory::editions()->list(
            $this->api->contextInfo(),
            $statuses,
            (int) $illuminateRequest->query('count', '20'),
            (int) $illuminateRequest->query('offset', '0'),
        );
        return response()->json([
            'items' => array_map(fn (Edition $e): array => $this->present($e), $result['items']),
            'itemsMax' => $result['itemsMax'],
        ], Response::HTTP_OK);
    }

    public function get(Request $illuminateRequest): JsonResponse
    {
        $edition = ServiceFactory::editions()->get($this->api->contextInfo(), (int) $illuminateRequest->route('editionId'));
        return $edition === null ? ConferenceApiController::notFound() : response()->json($this->present($edition), Response::HTTP_OK);
    }

    public function create(Request $illuminateRequest): JsonResponse
    {
        [$saved, $errors] = ServiceFactory::editions()->create(
            $this->api->contextInfo(),
            (array) $illuminateRequest->input(),
            $this->api->actorId(),
            $this->api->remoteAddr(),
        );
        return $saved === null
            ? ConferenceApiController::errorResponse($errors)
            : response()->json($this->present($saved), Response::HTTP_OK);
    }

    public function update(Request $illuminateRequest): JsonResponse
    {
        $result = ServiceFactory::editions()->update(
            $this->api->contextInfo(),
            (int) $illuminateRequest->route('editionId'),
            (array) $illuminateRequest->input(),
            $this->api->actorId(),
            $this->api->remoteAddr(),
        );
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        [$saved, $errors] = $result;
        return $saved === null
            ? ConferenceApiController::errorResponse($errors)
            : response()->json($this->present($saved), Response::HTTP_OK);
    }

    public function copy(Request $illuminateRequest): JsonResponse
    {
        $context = $this->api->contextInfo();
        $sourceId = (int) $illuminateRequest->route('editionId');
        $input = (array) $illuminateRequest->input();
        $parts = $input['copyParts'] ?? EditionCopyService::PARTS;
        unset($input['copyParts']);
        $result = ServiceFactory::editions()->copy($context, $sourceId, $input, $this->api->actorId(), $this->api->remoteAddr());
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        [$saved, $errors] = $result;
        if ($saved === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        // EDN-09: categories, tariffs, payment rules, rooms and settings come along.
        $copied = ServiceFactory::editionCopy()->copyParts($context, $sourceId, (int) $saved->id, is_array($parts) ? array_values(array_map('strval', $parts)) : [], $this->api->actorId());
        return response()->json($this->present($saved) + ['copied' => $copied], Response::HTTP_OK);
    }

    public function delete(Request $illuminateRequest): JsonResponse
    {
        $id = (int) $illuminateRequest->route('editionId');
        $errors = ServiceFactory::editions()->delete($this->api->contextInfo(), $id, $this->api->actorId(), $this->api->remoteAddr());
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty()
            ? response()->json(['id' => $id], Response::HTTP_OK)
            : ConferenceApiController::errorResponse($errors, Response::HTTP_CONFLICT);
    }

    public function options(Request $illuminateRequest): JsonResponse
    {
        $choices = static fn (array $cases): array => array_map(
            static fn (\BackedEnum $c): array => ['value' => $c->value, 'label' => __($c->labelKey())], // @phpstan-ignore method.notFound
            $cases,
        );
        $types = [];
        foreach (EventType::cases() as $type) {
            $types[] = [
                'value' => $type->value,
                'label' => __($type->labelKey()),
                'defaultSubmissionMode' => $type->defaultSubmissionMode()->value,
                'defaultPublicationMode' => $type->defaultPublicationMode()->value,
            ];
        }
        return response()->json([
            'eventTypes' => $types,
            'statuses' => $choices(EditionStatus::cases()),
            'submissionModes' => $choices(SubmissionMode::cases()),
            'publicationModes' => $choices(PublicationMode::cases()),
            'formats' => $choices(EventFormat::cases()),
            'reviewModes' => $choices(ReviewMode::cases()),
            'defaultTimezone' => ServiceFactory::editions()->defaultTimezone($this->api->contextInfo()),
        ], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Edition $edition): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $edition->toArray() + [
            'submissionsOpen' => EditionWindows::submissionsOpenAt($edition, $now),
            'registrationOpen' => EditionWindows::registrationOpenAt($edition, $now),
            'allowedStatuses' => array_map(static fn (EditionStatus $s): string => $s->value, $edition->status->allowedNext()),
        ];
    }
}
