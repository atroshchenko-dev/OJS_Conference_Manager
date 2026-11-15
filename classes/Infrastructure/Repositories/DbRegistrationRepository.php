<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\AuthorInfo;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\SpeakerRegistration;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * Speaker questionnaires, participants and participations with the query builder.
 */
final class DbRegistrationRepository implements RegistrationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findBySubmission(int $submissionId): ?SpeakerRegistration
    {
        $row = $this->db->table('conf_submission_registrations')->where('submission_id', $submissionId)->first();
        if ($row === null) {
            return null;
        }
        return new SpeakerRegistration(
            (int) $row->submission_id,
            $row->edition_id === null ? null : (int) $row->edition_id,
            $row->presentation_type === null ? null : PresentationType::tryFrom((string) $row->presentation_type),
            $row->participation_form === null ? null : ParticipationForm::tryFrom((string) $row->participation_form),
            $row->presenter_email === null ? null : (string) $row->presenter_email,
            $row->consent_version === null ? null : (string) $row->consent_version,
            DateInput::fromStorage($row->consent_at === null ? null : (string) $row->consent_at),
            $row->consent_user_id === null ? null : (int) $row->consent_user_id,
            DateInput::fromStorage($row->registered_at === null ? null : (string) $row->registered_at),
        );
    }

    public function save(SpeakerRegistration $registration): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'edition_id' => $registration->editionId,
            'presentation_type' => $registration->presentationType?->value,
            'participation_form' => $registration->participationForm?->value,
            'presenter_email' => $registration->presenterEmail,
            'consent_version' => $registration->consentVersion,
            'consent_at' => DateInput::toStorage($registration->consentAt),
            'consent_user_id' => $registration->consentUserId,
            'registered_at' => DateInput::toStorage($registration->registeredAt),
            'updated_at' => $now,
        ];
        $table = 'conf_submission_registrations';
        if ($this->db->table($table)->where('submission_id', $registration->submissionId)->exists()) {
            $this->db->table($table)->where('submission_id', $registration->submissionId)->update($values);
        } else {
            $this->db->table($table)->insert($values + ['submission_id' => $registration->submissionId, 'created_at' => $now]);
        }
    }

    public function upsertParticipant(
        int $contextId,
        int $editionId,
        AuthorInfo $author,
        ParticipantRole $role,
        ?ParticipationForm $form,
        int $submissionId,
        ?string $consentVersion,
        ?DateTimeImmutable $consentAt,
        DateTimeImmutable $now,
        bool $confirmed = false,
    ): array {
        return $this->db->transaction(function () use ($contextId, $editionId, $author, $role, $form, $submissionId, $consentVersion, $consentAt, $now, $confirmed): array {
            $stamp = DateInput::toStorage($now);
            $email = $author->normalizedEmail();
            $row = $this->db->table('conf_participants')
                ->where('edition_id', $editionId)->where('email', $email)
                ->lockForUpdate()->first();
            $isNew = $row === null;

            if ($isNew) {
                $participantId = (int) $this->db->table('conf_participants')->insertGetId([
                    'context_id' => $contextId,
                    'edition_id' => $editionId,
                    'user_id' => $author->userId,
                    'author_id' => $author->authorId,
                    'email' => $email,
                    'affiliation' => $this->cut($author->affiliation),
                    'country' => $author->country === null ? null : substr(strtoupper($author->country), 0, 2),
                    'orcid' => $this->cut($author->orcid),
                    'confirmation_status' => ($confirmed ? ConfirmationStatus::Confirmed : ConfirmationStatus::Pending)->value,
                    'confirmed_at' => $confirmed ? $stamp : null,
                    'consent_version' => $consentVersion,
                    'consent_at' => DateInput::toStorage($consentAt),
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
                $status = $confirmed ? ConfirmationStatus::Confirmed : ConfirmationStatus::Pending;
            } else {
                $participantId = (int) $row->id;
                $status = ConfirmationStatus::tryFrom((string) $row->confirmation_status) ?? ConfirmationStatus::Pending;
                $update = ['updated_at' => $stamp, 'author_id' => $author->authorId];
                if ($row->user_id === null && $author->userId !== null) {
                    $update['user_id'] = $author->userId;
                }
                if ($confirmed && $status !== ConfirmationStatus::Confirmed) {
                    $status = ConfirmationStatus::Confirmed;
                    $update += ['confirmation_status' => $status->value, 'confirmed_at' => $stamp, 'confirmation_token_hash' => null, 'confirmation_expires_at' => null];
                }
                if ($consentVersion !== null) {
                    $update += ['consent_version' => $consentVersion, 'consent_at' => DateInput::toStorage($consentAt)];
                }
                $this->db->table('conf_participants')->where('id', $participantId)->update($update);
            }

            $this->writeNames($participantId, $author);

            $participation = $this->db->table('conf_participations')
                ->where('participant_id', $participantId)->where('submission_id', $submissionId)->first();
            $values = [
                'role' => $role->value,
                'participation_form' => ($form ?? ParticipationForm::InPerson)->value,
                'updated_at' => $stamp,
            ];
            if ($participation === null) {
                $this->db->table('conf_participations')->insert($values + [
                    'participant_id' => $participantId,
                    'submission_id' => $submissionId,
                    'status' => 'active',
                    'created_at' => $stamp,
                ]);
            } else {
                $this->db->table('conf_participations')->where('id', $participation->id)->update($values);
            }

            return ['participantId' => $participantId, 'isNew' => $isNew, 'confirmationStatus' => $status];
        });
    }

    public function setConfirmationToken(int $participantId, string $hash, DateTimeImmutable $expiresAt): void
    {
        $this->db->table('conf_participants')->where('id', $participantId)->update([
            'confirmation_token_hash' => $hash,
            'confirmation_expires_at' => DateInput::toStorage($expiresAt),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function findByTokenHash(string $hash): ?array
    {
        $row = $this->db->table('conf_participants')->where('confirmation_token_hash', $hash)->first();
        if ($row === null) {
            return null;
        }
        return [
            'participantId' => (int) $row->id,
            'contextId' => (int) $row->context_id,
            'editionId' => (int) $row->edition_id,
            'email' => (string) $row->email,
            'status' => ConfirmationStatus::tryFrom((string) $row->confirmation_status) ?? ConfirmationStatus::Pending,
            'expiresAt' => DateInput::fromStorage($row->confirmation_expires_at === null ? null : (string) $row->confirmation_expires_at),
        ];
    }

    public function confirm(int $participantId, ConfirmationStatus $status, DateTimeImmutable $now): void
    {
        $this->db->table('conf_participants')->where('id', $participantId)->update([
            'confirmation_status' => $status->value,
            'confirmed_at' => DateInput::toStorage($now),
            'confirmation_token_hash' => null,
            'confirmation_expires_at' => null,
            'updated_at' => DateInput::toStorage($now),
        ]);
    }

    public function participantsOfSubmission(int $submissionId): array
    {
        $rows = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('pn.submission_id', $submissionId)
            ->orderBy('p.id')
            ->get(['p.id', 'p.email', 'pn.role', 'p.confirmation_status', 'pn.participation_form'])->all();
        return array_map(static fn (object $r): array => [
            'participantId' => (int) $r->id,
            'email' => (string) $r->email,
            'role' => (string) $r->role,
            'confirmationStatus' => (string) $r->confirmation_status,
            'participationForm' => (string) $r->participation_form,
        ], $rows);
    }

    private function writeNames(int $participantId, AuthorInfo $author): void
    {
        foreach (['givenName' => $author->givenName, 'familyName' => $author->familyName] as $name => $values) {
            foreach ($values as $locale => $value) {
                if (trim($value) === '') {
                    continue;
                }
                $this->db->table('conf_participant_settings')->updateOrInsert(
                    ['participant_id' => $participantId, 'locale' => $locale, 'setting_name' => $name],
                    ['setting_value' => trim($value)],
                );
            }
        }
    }

    private function cut(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === null || $value === '' ? null : mb_substr($value, 0, 255);
    }
}
