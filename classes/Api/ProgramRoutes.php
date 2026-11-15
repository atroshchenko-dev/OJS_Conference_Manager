<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * The program builder (PRG-02, PRG-03). Journal manager:
 *
 * GET    editions/{id}/program-builder            rooms, sessions with papers and times, unassigned papers, conflicts
 * POST   editions/{id}/rooms                      {name, capacity, onlineUrl}
 * PUT    editions/{id}/rooms/{roomId}
 * DELETE editions/{id}/rooms/{roomId}             its sessions stay without a room
 * POST   editions/{id}/sessions                   {type, roomId, startsAt, endsAt, slotMinutes, title{}, chairs[], …}
 * PUT    editions/{id}/sessions/{sessionId}
 * DELETE editions/{id}/sessions/{sessionId}       its papers go back to the unassigned list
 * POST   editions/{id}/program-moves              {submissionId, sessionId|null, position, durationMin}
 */
final class ProgramRoutes implements RouteModule
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
        $base = 'editions/{editionId}';
        Route::get($base . '/program-builder', $this->getBuilder(...))->name('conference.program.builder')->whereNumber('editionId');
        Route::post($base . '/rooms', $this->addRoom(...))->name('conference.program.rooms.add')->whereNumber('editionId');
        Route::put($base . '/rooms/{roomId}', $this->editRoom(...))->name('conference.program.rooms.edit')->whereNumber(['editionId', 'roomId']);
        Route::delete($base . '/rooms/{roomId}', $this->deleteRoom(...))->name('conference.program.rooms.delete')->whereNumber(['editionId', 'roomId']);
        Route::post($base . '/sessions', $this->addSession(...))->name('conference.program.sessions.add')->whereNumber('editionId');
        Route::put($base . '/sessions/{sessionId}', $this->editSession(...))->name('conference.program.sessions.edit')->whereNumber(['editionId', 'sessionId']);
        Route::delete($base . '/sessions/{sessionId}', $this->deleteSession(...))->name('conference.program.sessions.delete')->whereNumber(['editionId', 'sessionId']);
        Route::post($base . '/program-moves', $this->movePaper(...))->name('conference.program.move')->whereNumber('editionId');
    }

    private function edition(Request $r): int
    {
        return (int) $r->route('editionId');
    }

    public function getBuilder(Request $r): JsonResponse
    {
        $data = ServiceFactory::programBuilder()->overview($this->api->contextInfo(), $this->edition($r), \PKP\facades\Locale::getLocale());
        if ($data === null) {
            return ConferenceApiController::notFound();
        }
        $data['types'] = array_map(static fn (SessionType $t): array => ['value' => $t->value, 'label' => __($t->labelKey())], SessionType::cases());
        return response()->json($data, Response::HTTP_OK);
    }

    public function addRoom(Request $r): JsonResponse
    {
        return $this->room($r, null);
    }

    public function editRoom(Request $r): JsonResponse
    {
        return $this->room($r, (int) $r->route('roomId'));
    }

    private function room(Request $r, ?int $id): JsonResponse
    {
        $result = ServiceFactory::programBuilder()->saveRoom($this->api->contextInfo(), $this->edition($r), $id, (array) $r->input(), $this->api->actorId());
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        [$room, $errors] = $result;
        return $room === null ? ConferenceApiController::errorResponse($errors) : response()->json($room->toArray(), $id === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function deleteRoom(Request $r): JsonResponse
    {
        return ServiceFactory::programBuilder()->deleteRoom($this->api->contextInfo(), $this->edition($r), (int) $r->route('roomId'), $this->api->actorId())
            ? response()->json(['deleted' => true], Response::HTTP_OK)
            : ConferenceApiController::notFound();
    }

    public function addSession(Request $r): JsonResponse
    {
        return $this->session($r, null);
    }

    public function editSession(Request $r): JsonResponse
    {
        return $this->session($r, (int) $r->route('sessionId'));
    }

    private function session(Request $r, ?int $id): JsonResponse
    {
        $context = $this->api->contextInfo();
        $result = ServiceFactory::programBuilder()->saveSession($context, $this->edition($r), $id, (array) $r->input(), $this->api->actorId());
        if ($result === null) {
            return ConferenceApiController::notFound();
        }
        [$session, $errors] = $result;
        if ($session === null) {
            return ConferenceApiController::errorResponse($errors);
        }
        $edition = ServiceFactory::editions()->get($context, $this->edition($r));
        return response()->json($session->toArray($edition->timezone ?? 'UTC'), $id === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function deleteSession(Request $r): JsonResponse
    {
        return ServiceFactory::programBuilder()->deleteSession($this->api->contextInfo(), $this->edition($r), (int) $r->route('sessionId'), $this->api->actorId())
            ? response()->json(['deleted' => true], Response::HTTP_OK)
            : ConferenceApiController::notFound();
    }

    public function movePaper(Request $r): JsonResponse
    {
        $session = $r->input('sessionId');
        $errors = ServiceFactory::programBuilder()->move(
            $this->api->contextInfo(),
            $this->edition($r),
            (int) $r->input('submissionId', 0),
            $session === null || $session === '' ? null : (int) $session,
            max(0, (int) $r->input('position', 0)),
            $r->input('durationMin'),
            $this->api->actorId(),
        );
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty() ? response()->json(['moved' => true], Response::HTTP_OK) : ConferenceApiController::errorResponse($errors);
    }
}
