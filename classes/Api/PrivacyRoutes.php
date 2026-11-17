<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\PersonGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Personal data (DAT-02, DAT-03, DAT-04).
 *
 * Journal manager:
 * GET  retention                         {proofsMonths, participantsMonths}
 * PUT  retention                         same; 0 keeps the data
 * GET  personal-data?email=              everything about the person (JSON file)
 * POST personal-data/anonymize           {email} or {participantIds} → {anonymized}
 *
 * Any signed-in user:
 * GET  me/personal-data                  their own data (JSON file)
 */
final class PrivacyRoutes implements RouteModule
{
    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [
            Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_READER,
        ];
    }

    public function routes(): void
    {
        Route::get('retention', $this->getRetention(...))->name('conference.retention.get');
        Route::put('retention', $this->editRetention(...))->name('conference.retention.edit');
        Route::get('personal-data', $this->exportPersonalData(...))->name('conference.personalData.export');
        Route::post('personal-data/anonymize', $this->anonymizePerson(...))->name('conference.personalData.anonymize');
        Route::get('me/personal-data', $this->exportMyData(...))->name('conference.me.personalData');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function isManager(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    public function getRetention(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(ServiceFactory::personalData()->retention($this->api->contextInfo()), Response::HTTP_OK);
    }

    public function editRetention(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $service = ServiceFactory::personalData();
        $errors = $service->saveRetention($this->api->contextInfo(), (array) $r->input(), $this->api->actorId());
        return $errors->isEmpty() ? response()->json($service->retention($this->api->contextInfo()), Response::HTTP_OK) : ConferenceApiController::errorResponse($errors);
    }

    public function exportPersonalData(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $email = trim((string) $r->query('email', ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ConferenceApiController::notFound();
        }
        return self::file(ServiceFactory::personalData()->export($this->api->contextInfo(), null, $email, $this->api->actorId(), self::now()));
    }

    public function anonymizePerson(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $ids = $r->input('participantIds');
        $email = trim((string) $r->input('email', ''));
        if (!is_array($ids) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ConferenceApiController::notFound();
        }
        $done = ServiceFactory::personalData()->anonymize(
            $this->api->contextInfo(),
            is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : null,
            $email,
            $this->api->actorId(),
            self::now(),
        );
        return response()->json(['anonymized' => $done], Response::HTTP_OK);
    }

    public function exportMyData(Request $r): JsonResponse
    {
        $userId = (int) $this->api->actorId();
        $person = (new PersonGateway())->of($userId, \PKP\facades\Locale::getLocale());
        return self::file(ServiceFactory::personalData()->export($this->api->contextInfo(), $userId, (string) ($person['matchEmail'] ?? ''), $userId, self::now()));
    }

    /** @param array<string, mixed> $data */
    private static function file(array $data): JsonResponse
    {
        return response()->json($data, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="personal-data-' . gmdate('Ymd') . '.json"',
            'Cache-Control' => 'no-store',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
