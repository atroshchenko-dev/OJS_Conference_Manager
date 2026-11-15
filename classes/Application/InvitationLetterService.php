<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\InvitationLetterHtml;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\DocumentType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * COM-04: a letter of invitation in PDF for a business trip or a visa.
 * The participant downloads it in the cabinet, a registration manager
 * from the register. Authors get it once their paper is accepted, other
 * participants as soon as their participation is active.
 */
final class InvitationLetterService
{
    private const K = 'plugins.generic.conferenceManager.invitationLetter.';

    /** Languages the letter has texts in. */
    public const LANGUAGES = ['uk', 'en'];

    public function __construct(
        private readonly ParticipantRepository $participants,
        private readonly EditionRepository $editions,
        private readonly EditorialDirectory $editorial,
        private readonly InstitutionProfileService $institutions,
        private readonly ?PdfRenderer $pdf,
        /** @var \Closure(string, array<string, mixed>, string): string */
        private readonly \Closure $translate,
    ) {
    }

    /**
     * Whether the letter can be given for the participation.
     *
     * @param array<string, mixed> $p participation (findParticipation)
     */
    public function isAvailable(ContextInfo $context, array $p): bool
    {
        return $this->available($context, [$p]) !== [];
    }

    /**
     * The participations among $rows whose letter can be downloaded now, by
     * participation id (one query for all the submissions).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    public function available(ContextInfo $context, array $rows): array
    {
        $authors = [ParticipantRole::Presenter->value, ParticipantRole::Coauthor->value];
        $ids = [];
        foreach ($rows as $p) {
            if (($p['status'] ?? '') === ParticipationStatus::Active->value && ($p['submissionId'] ?? null) !== null && in_array($p['role'] ?? '', $authors, true)) {
                $ids[] = (int) $p['submissionId'];
            }
        }
        $summaries = $ids === [] ? [] : $this->editorial->summaries(array_values(array_unique($ids)), $context->primaryLocale);
        $out = [];
        foreach ($rows as $p) {
            if (($p['status'] ?? '') !== ParticipationStatus::Active->value) {
                continue;
            }
            $submissionId = $p['submissionId'] ?? null;
            if ($submissionId !== null && in_array($p['role'] ?? '', $authors, true)) {
                $s = $summaries[(int) $submissionId] ?? null;
                if ($s === null || $s['contextId'] !== $context->id
                    || $s['status'] === EditorialDirectory::STATUS_DECLINED || $s['stageId'] < EditorialDirectory::STAGE_EDITING) {
                    continue;
                }
            }
            $out[] = (int) ($p['participationId'] ?? 0);
        }
        return $out;
    }

    /**
     * The PDF, or null when the participation is not found, not the
     * person's own (unless $ownerParticipantIds is null: a manager) or the
     * letter is not available yet.
     *
     * @param list<int>|null $ownerParticipantIds
     * @return array{name: string, content: string}|null
     */
    public function pdf(ContextInfo $context, int $participationId, ?array $ownerParticipantIds, ?string $language, DateTimeImmutable $now): ?array
    {
        $p = $this->participants->findParticipation($context->id, $participationId);
        if ($p === null || $this->pdf === null) {
            return null;
        }
        if ($ownerParticipantIds !== null && !in_array($p['participantId'], $ownerParticipantIds, true)) {
            return null;
        }
        $edition = $this->editions->find($context->id, $p['editionId']);
        if ($edition === null || !$this->isAvailable($context, $p)) {
            return null;
        }
        $doc = in_array($language, self::LANGUAGES, true) ? (string) $language : (in_array(substr($context->primaryLocale, 0, 2), self::LANGUAGES, true) ? substr($context->primaryLocale, 0, 2) : 'en');
        $html = InvitationLetterHtml::render($this->values($context, $edition, $p, $doc, $now));
        $number = $this->number($edition, $participationId);
        return ['name' => 'invitation-' . str_replace('/', '-', $number) . '.pdf', 'content' => $this->pdf->render($html, $number)];
    }

    /**
     * @param array<string, mixed> $p
     * @return array{institution: string, address: string, legalIdLine: string, contacts: string, numberLine: string,
     *   addresseeName: string, addresseeAffiliation: string, title: string, salutation: string,
     *   paragraphs: list<string>, signerPosition: string, signerName: string}
     */
    private function values(ContextInfo $context, Edition $edition, array $p, string $doc, DateTimeImmutable $now): array
    {
        $primary = $context->primaryLocale;
        $profile = $this->institutions->getOrDraft($context);
        $person = $this->participants->payer($p['participantId'], $doc) ?? ['name' => '', 'affiliation' => ''];
        $tz = new DateTimeZone($edition->timezone);
        $start = $edition->date('startsAt')?->setTimezone($tz);
        $end = $edition->date('endsAt')?->setTimezone($tz);
        $dates = $start === null ? '' : ($end === null || $start->format('Y-m-d') === $end->format('Y-m-d')
            ? $start->format('d.m.Y') : $start->format('d.m.Y') . ' – ' . $end->format('d.m.Y'));
        $institution = (string) $profile->text('name')->resolve($doc, $primary);
        $venue = (string) $edition->text('venue')->resolve($doc, $primary);
        $role = ParticipantRole::tryFrom((string) $p['role']);
        $paper = '';
        if ($p['submissionId'] !== null) {
            $paper = (string) ($this->editorial->summaries([(int) $p['submissionId']], $doc === 'uk' ? 'uk' : 'en')[(int) $p['submissionId']]['title'] ?? '');
        }
        $form = ParticipationForm::tryFrom((string) ($p['participationForm'] ?? ''));
        $signer = $profile->signerFor(DocumentType::InvitationLetter) ?? ($profile->signers[0] ?? null);
        $legalId = (string) $profile->legalId;
        $params = [
            'institution' => $institution,
            'conference' => (string) $edition->text('name')->resolve($doc, $primary),
            'eventType' => $this->t($edition->eventType->labelKey(), [], $doc),
            'dates' => $dates,
            'venue' => $venue,
            'role' => $role === null ? (string) $p['role'] : $this->t($role->labelKey(), [], $doc),
            'paper' => $paper,
            'form' => $form === null ? '' : $this->t($form->labelKey(), [], $doc),
        ];
        return [
            'institution' => $institution,
            'address' => (string) $profile->text('address')->resolve($doc, $primary),
            'legalIdLine' => $legalId === '' ? '' : $this->t($this->institutions->legalIdLabelKey($context), [], $doc) . ': ' . $legalId,
            'contacts' => trim(implode(' · ', array_filter([$profile->contactEmail, $profile->website]))),
            'numberLine' => $this->t(self::K . 'number', ['number' => $this->number($edition, (int) $p['participationId']), 'date' => $now->setTimezone($tz)->format('d.m.Y')], $doc),
            'addresseeName' => (string) $person['name'],
            'addresseeAffiliation' => (string) $person['affiliation'],
            'title' => $this->t(self::K . 'title', [], $doc),
            'salutation' => $person['name'] === '' ? '' : $this->t(self::K . 'salutation', ['name' => $person['name']], $doc),
            'paragraphs' => [
                $this->t(self::K . ($venue === '' ? 'textNoVenue' : 'text'), $params, $doc),
                $paper !== '' ? $this->t(self::K . 'paper', $params, $doc) : '',
                $params['form'] !== '' ? $this->t(self::K . 'form', $params, $doc) : '',
                $this->t(self::K . 'purpose', $params, $doc),
            ],
            'signerPosition' => $signer === null ? '' : (string) $signer->position->resolve($doc, $primary),
            'signerName' => $signer === null ? '' : (string) $signer->fullName->resolve($doc, $primary),
        ];
    }

    /** Outgoing number: the edition path and the participation, unique in the journal. */
    private function number(Edition $edition, int $participationId): string
    {
        return $edition->path . '/' . $participationId;
    }

    /** @param array<string, mixed> $params */
    private function t(string $key, array $params, string $locale): string
    {
        return ($this->translate)($key, $params, $locale);
    }
}
