<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Core\SubmissionGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationValidator as F;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\SpeakerRegistration;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Speaker questionnaire of a submission (REG-02):
 *
 * GET submissions/{submissionId}/registration   questionnaire and participants
 * PUT submissions/{submissionId}/registration   autosave of the wizard step
 *
 * The wizard sends the field names of the step (conferenceEditionId …).
 * Responses never contain "submissionId" or "dateSubmitted" at the top
 * level: the wizard would take them for a publication or a submission.
 */
final class RegistrationRoutes implements RouteModule
{
    /** Route actions that need access to the submission (see ConferenceApiController::authorize). */
    public const SUBMISSION_ACTIONS = ['getSubmissionRegistration', 'putSubmissionRegistration'];

    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_AUTHOR];
    }

    public function routes(): void
    {
        Route::get('submissions/{submissionId}/registration', $this->getSubmissionRegistration(...))
            ->name('conference.submissions.registration.get')->whereNumber('submissionId');
        Route::put('submissions/{submissionId}/registration', $this->putSubmissionRegistration(...))
            ->name('conference.submissions.registration.put')->whereNumber('submissionId');
    }

    public function getSubmissionRegistration(Request $illuminateRequest): JsonResponse
    {
        $id = (int) $illuminateRequest->route('submissionId');
        $service = ServiceFactory::registrations();
        return response()->json([
            'registration' => $this->present($service->get($id)),
            'participants' => $service->participantsOfSubmission($id),
        ], Response::HTTP_OK);
    }

    public function putSubmissionRegistration(Request $illuminateRequest): JsonResponse
    {
        $id = (int) $illuminateRequest->route('submissionId');
        $context = $this->api->contextInfo();
        $submission = (new SubmissionGateway())->find($id);
        if ($submission === null || $submission->contextId !== $context->id) {
            return ConferenceApiController::notFound();
        }
        $input = [];
        $map = [
            F::FIELD_EDITION => 'editionId',
            F::FIELD_PRESENTATION => 'presentationType',
            F::FIELD_FORM => 'participationForm',
            F::FIELD_PRESENTER => 'presenterEmail',
            F::FIELD_CONSENT => 'consent',
            F::FIELD_CATEGORY => 'categoryId',
            F::FIELD_MINOR => 'presenterMinor',
            F::FIELD_GUARDIAN => 'guardianConsent',
        ];
        foreach ($map as $field => $key) {
            if ($illuminateRequest->has($field)) {
                $input[$key] = $illuminateRequest->input($field);
            }
        }
        // The wizard posts the whole form as form data, where an unticked
        // checkbox (an empty array) is left out.
        if (!array_key_exists('consent', $input) && (array_key_exists('editionId', $input) || array_key_exists('presenterEmail', $input))) {
            $input['consent'] = false;
            $input['presenterMinor'] ??= false;
            $input['guardianConsent'] ??= false;
        }
        $locale = \PKP\facades\Locale::getLocale();
        [$saved, $errors] = ServiceFactory::registrations()->save(
            $context,
            $submission,
            $input,
            ServiceFactory::consentText($context, $locale)->version,
            $this->api->actorId(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->api->userHasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR]),
        );
        if ($saved === null) {
            return ConferenceApiController::errorResponse($errors, Response::HTTP_CONFLICT);
        }
        $warnings = [];
        foreach ($errors->toArray() as $field => $keys) {
            $warnings[$field] = array_map(static fn (string $k): string => __($k), $keys);
        }
        return response()->json(['registration' => $this->present($saved), 'warnings' => $warnings], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SpeakerRegistration $r): array
    {
        return [
            'editionId' => $r->editionId,
            'presentationType' => $r->presentationType?->value,
            'participationForm' => $r->participationForm?->value,
            'presenterEmail' => $r->presenterEmail,
            'consentVersion' => $r->consentVersion,
            'consentAt' => DateInput::toIsoUtc($r->consentAt),
            'registeredAt' => DateInput::toIsoUtc($r->registeredAt),
            'categoryId' => $r->categoryId,
            'presenterMinor' => $r->presenterMinor,
            'guardianConsentAt' => DateInput::toIsoUtc($r->guardianConsentAt),
        ];
    }
}
