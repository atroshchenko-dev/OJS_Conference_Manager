<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionWindows;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Listeners, the participant's own records and the register of
 * participants (REG-05…08).
 *
 * Access rules live here, not in the controllers: a person sees and
 * cancels only participations found by their own user id or email; a
 * manager sees and cancels any participation of the context.
 */
final class ParticipantService
{
    private const K = 'plugins.generic.conferenceManager.listener.error.';

    public const CANCELLED = 'cancelled';
    public const NOT_FOUND = 'notFound';
    public const FORBIDDEN = 'forbidden';
    public const DEADLINE = 'deadlinePassed';
    public const ALREADY = 'alreadyCancelled';

    public function __construct(
        private readonly ParticipantRepository $participants,
        private readonly EditionRepository $editions,
        private readonly FeeRepository $fees,
        private readonly SubmissionDirectory $submissions,
        private readonly ?ParticipantGroups $groups = null,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string): string)|null translates a locale key; the key itself without it */
        private readonly ?\Closure $translate = null,
        /** issues the listener's invoice right after registration (FEE-04) */
        private readonly ?InvoiceService $invoices = null,
    ) {
    }

    private function t(string $key): string
    {
        return $this->translate === null ? $key : ($this->translate)($key);
    }

    public function edition(ContextInfo $context, int $editionId): ?Edition
    {
        return $this->editions->find($context->id, $editionId);
    }

    /**
     * Participation forms a listener can choose for the edition format.
     *
     * @return list<ParticipationForm>
     */
    public static function listenerForms(Edition $edition): array
    {
        return match ($edition->format) {
            EventFormat::InPerson => [ParticipationForm::InPerson],
            EventFormat::Online => [ParticipationForm::Online],
            EventFormat::Hybrid => [ParticipationForm::InPerson, ParticipationForm::Online],
        };
    }

    /**
     * REG-05: a logged-in person registers as a listener.
     *
     * @param array{userId: int, email: string, givenName: array<string, string>, familyName: array<string, string>,
     *   affiliation: ?string, country: ?string, orcid: ?string, position: ?string} $person
     * @param array{categoryId?: mixed, participationForm?: mixed, consent?: mixed, minor?: mixed, guardianConsent?: mixed} $input
     * @return array{0: array{participantId: int, participationId: int}|null, 1: ValidationErrors}
     */
    public function registerListener(
        ContextInfo $context,
        int $editionId,
        array $person,
        array $input,
        string $consentVersion,
        DateTimeImmutable $now,
        ?string $ip = null,
    ): array {
        $errors = new ValidationErrors();
        $edition = $this->edition($context, $editionId);
        if ($edition === null) {
            $errors->add('editionId', self::K . 'edition');
            return [null, $errors];
        }
        if (!EditionWindows::registrationOpenAt($edition, $now)) {
            $errors->add('editionId', self::K . 'closed');
            return [null, $errors];
        }

        $categoryId = null;
        $categories = $this->fees->categories($editionId);
        if ($categories !== []) {
            $wanted = (int) ($input['categoryId'] ?? 0);
            $match = array_filter($categories, static fn (FeeCategory $c): bool => $c->id === $wanted);
            if ($match === []) {
                $errors->add('categoryId', self::K . 'category');
            } else {
                $categoryId = $wanted;
            }
        }

        $allowed = self::listenerForms($edition);
        $form = ParticipationForm::tryFrom((string) ($input['participationForm'] ?? ''));
        if ($form === null && count($allowed) === 1) {
            $form = $allowed[0];
        }
        if ($form === null || !in_array($form, $allowed, true)) {
            $errors->add('participationForm', self::K . 'form');
        }

        if (!filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $errors->add('consent', 'plugins.generic.conferenceManager.registration.error.consentRequired');
        }
        // EVT-04: without the legal representative's consent a minor is not registered.
        $minor = filter_var($input['minor'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($minor && !filter_var($input['guardianConsent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $errors->add('guardianConsent', 'plugins.generic.conferenceManager.registration.error.guardianRequired');
        }
        if (trim($person['email']) === '') {
            $errors->add('email', self::K . 'email');
        }
        if (!$errors->isEmpty() || $form === null) {
            return [null, $errors];
        }

        $result = $this->participants->addListener($context->id, $editionId, $person, $categoryId, $form, $consentVersion, $now, $minor);
        if ($result === null) {
            $errors->add('editionId', self::K . 'already');
            return [null, $errors];
        }
        $this->groups?->assignParticipant($context->id, $person['userId']);
        $this->audit?->log($context->id, $person['userId'], 'participation', $result['participationId'], 'listener_registered', null, [
            'editionId' => $editionId,
            'categoryId' => $categoryId,
            'participationForm' => $form->value,
            'consentVersion' => $consentVersion,
            'minor' => $minor,
        ], $ip);
        try {
            $this->invoices?->issueFor($context, $result['participationId'], null, $person['userId'], $now);
        } catch (\Throwable $e) {
            // The registration stands; the manager can issue the invoice later.
            error_log('conferenceManager: listener invoice failed: ' . $e->getMessage());
        }
        return [$result, $errors];
    }

    /**
     * REG-06: participations of the person, newest first, with what the
     * cabinet shows.
     *
     * @return list<array<string, mixed>>
     */
    public function myParticipations(ContextInfo $context, int $userId, string $email, string $locale, DateTimeImmutable $now): array
    {
        $out = [];
        $editions = [];
        $categories = [];
        foreach ($this->participants->ofPerson($context->id, $userId, $email) as $p) {
            $editionId = $p['editionId'];
            $editions[$editionId] ??= $this->edition($context, $editionId);
            $edition = $editions[$editionId];
            if ($edition === null) {
                continue;
            }
            $categories[$editionId] ??= $this->categoryNames($editionId, $locale, $context->primaryLocale);
            $title = '';
            if ($p['submissionId'] !== null) {
                $submission = $this->submissions->find($p['submissionId']);
                $title = $submission === null ? '' : (string) ($submission->title[$locale] ?? $submission->title[$submission->locale] ?? (array_values($submission->title)[0] ?? ''));
            }
            $status = ParticipationStatus::tryFrom($p['status']) ?? ParticipationStatus::Active;
            $out[] = $p + [
                'editionName' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
                'editionPath' => $edition->path,
                'startsAt' => DateInput::toIsoUtc($edition->date('startsAt')),
                'timezone' => $edition->timezone,
                'categoryName' => $p['categoryId'] === null ? '' : ($categories[$editionId][$p['categoryId']] ?? ''),
                'submissionTitle' => $title,
                'roleLabel' => $this->t((ParticipantRole::tryFrom($p['role']) ?? ParticipantRole::Listener)->labelKey()),
                'statusLabel' => $this->t($status->labelKey()),
                'confirmationLabel' => $this->t((ConfirmationStatus::tryFrom($p['confirmationStatus']) ?? ConfirmationStatus::Pending)->labelKey()),
                'formLabel' => $this->t((ParticipationForm::tryFrom($p['participationForm']) ?? ParticipationForm::InPerson)->labelKey()),
                'canCancel' => $status === ParticipationStatus::Active && $this->beforeDeadline($edition, $now),
                'cancelDeadline' => DateInput::toIsoUtc(self::cancelDeadline($edition)),
            ];
        }
        return $out;
    }

    /**
     * REG-08: the participant cancels before the registration deadline
     * (else before the end of the event); a manager cancels at any time.
     * When the participant has no active participations left, the
     * invoices are settled.
     *
     * @return array{result: string, invoices?: array{cancelled: int, refundRequested: int}}
     */
    public function cancel(
        ContextInfo $context,
        int $participationId,
        ?int $userId,
        string $email,
        bool $isManager,
        DateTimeImmutable $now,
        ?string $ip = null,
    ): array {
        $p = $this->participants->findParticipation($context->id, $participationId);
        if ($p === null) {
            return ['result' => self::NOT_FOUND];
        }
        $own = ($userId !== null && $p['userId'] === $userId)
            || ($email !== '' && mb_strtolower(trim($email)) === mb_strtolower($p['email']));
        if (!$own && !$isManager) {
            return ['result' => self::FORBIDDEN];
        }
        if ($p['status'] !== ParticipationStatus::Active->value) {
            return ['result' => self::ALREADY];
        }
        $edition = $this->edition($context, $p['editionId']);
        if ($edition === null) {
            return ['result' => self::NOT_FOUND];
        }
        if (!$isManager && !$this->beforeDeadline($edition, $now)) {
            return ['result' => self::DEADLINE];
        }

        $this->participants->cancel($participationId, $now);
        $invoices = ['cancelled' => 0, 'refundRequested' => 0];
        if (!$this->participants->hasActiveParticipations($p['participantId'])) {
            $invoices = $this->participants->settleInvoices($p['participantId'], $p['editionId'], $userId, $now);
        }
        $this->audit?->log($context->id, $userId, 'participation', $participationId, 'cancelled', ['status' => $p['status']], [
            'status' => ParticipationStatus::Cancelled->value,
            'by' => $own ? 'participant' : 'manager',
            'invoices' => $invoices,
        ], $ip);
        return ['result' => self::CANCELLED, 'invoices' => $invoices];
    }

    /**
     * REG-07: one page of the register, or null when there is no such edition.
     *
     * @return array{items: list<array<string, mixed>>, itemsMax: int}|null
     */
    public function register(ContextInfo $context, int $editionId, ParticipantFilter $filter, string $locale): ?array
    {
        if ($this->edition($context, $editionId) === null) {
            return null;
        }
        $page = $this->participants->register($context->id, $editionId, $filter, $locale);
        $names = $this->categoryNames($editionId, $locale, $context->primaryLocale);
        foreach ($page['items'] as &$item) {
            $item['categoryName'] = $item['categoryId'] === null ? '' : ($names[$item['categoryId']] ?? '');
            $item['roleLabel'] = $this->t((ParticipantRole::tryFrom((string) $item['role']) ?? ParticipantRole::Listener)->labelKey());
            $item['statusLabel'] = $this->t((ParticipationStatus::tryFrom((string) $item['status']) ?? ParticipationStatus::Active)->labelKey());
            $item['confirmationLabel'] = $this->t((ConfirmationStatus::tryFrom((string) $item['confirmationStatus']) ?? ConfirmationStatus::Pending)->labelKey());
            $item['formLabel'] = $this->t((ParticipationForm::tryFrom((string) $item['participationForm']) ?? ParticipationForm::InPerson)->labelKey());
        }
        unset($item);
        return $page;
    }

    /**
     * REG-07: the whole filtered register as CSV for spreadsheets: UTF-8
     * with BOM, ";" as separator (the default of Ukrainian Excel), cells
     * that a spreadsheet would run as a formula are prefixed with "'".
     */
    public function exportCsv(ContextInfo $context, int $editionId, ParticipantFilter $filter, string $locale): ?string
    {
        $columns = [
            'participationId' => 'ID',
            'name' => $this->t('plugins.generic.conferenceManager.participants.name'),
            'email' => $this->t('plugins.generic.conferenceManager.participants.email'),
            'affiliation' => $this->t('plugins.generic.conferenceManager.participants.affiliation'),
            'position' => $this->t('plugins.generic.conferenceManager.participants.position'),
            'country' => $this->t('plugins.generic.conferenceManager.participants.country'),
            'roleLabel' => $this->t('plugins.generic.conferenceManager.participants.role'),
            'categoryName' => $this->t('plugins.generic.conferenceManager.participants.category'),
            'formLabel' => $this->t('plugins.generic.conferenceManager.participants.form'),
            'confirmationLabel' => $this->t('plugins.generic.conferenceManager.participants.confirmation'),
            'statusLabel' => $this->t('plugins.generic.conferenceManager.participants.status'),
            'submissionId' => $this->t('plugins.generic.conferenceManager.participants.submission'),
            'createdAt' => $this->t('plugins.generic.conferenceManager.participants.createdAt'),
        ];
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return null;
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($columns), ';', '"', '');
        $offset = 0;
        do {
            $page = $this->register($context, $editionId, $filter->page(ParticipantFilter::MAX_PAGE, $offset), $locale);
            if ($page === null) {
                fclose($out);
                return null;
            }
            foreach ($page['items'] as $item) {
                $row = [];
                foreach (array_keys($columns) as $key) {
                    $row[] = self::safeCell((string) ($item[$key] ?? ''));
                }
                fputcsv($out, $row, ';', '"', '');
            }
            $offset += ParticipantFilter::MAX_PAGE;
        } while ($offset < $page['itemsMax']);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * The manager sets or clears the category of a participant (FEE-01).
     */
    public function setCategory(ContextInfo $context, int $participationId, ?int $categoryId, ?int $actorId = null): ?ValidationErrors
    {
        $p = $this->participants->findParticipation($context->id, $participationId);
        if ($p === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if ($categoryId !== null && $this->fees->findCategory($p['editionId'], $categoryId) === null) {
            $errors->add('categoryId', self::K . 'category');
            return $errors;
        }
        $this->participants->setCategory($p['participantId'], $categoryId);
        $this->audit?->log($context->id, $actorId, 'participant', $p['participantId'], 'category_set', null, ['categoryId' => $categoryId]);
        return $errors;
    }

    public static function cancelDeadline(Edition $edition): ?DateTimeImmutable
    {
        return $edition->date('registrationClosesAt') ?? $edition->date('endsAt');
    }

    private function beforeDeadline(Edition $edition, DateTimeImmutable $now): bool
    {
        $deadline = self::cancelDeadline($edition);
        return $deadline === null || $now < $deadline;
    }

    /**
     * @return array<int, string>
     */
    private function categoryNames(int $editionId, string $locale, string $primaryLocale): array
    {
        $out = [];
        foreach ($this->fees->categories($editionId) as $c) {
            if ($c->id !== null) {
                $out[$c->id] = (string) $c->name->resolve($locale, $primaryLocale);
            }
        }
        return $out;
    }

    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
