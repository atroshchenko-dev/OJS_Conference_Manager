<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateDocument;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateNumber;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateRules;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Certificates (CRT-01, 02, 04, 05, 07).
 *
 * A certificate is issued from a snapshot: the template text and every
 * value at the moment of issue are stored with the record, the number
 * comes from the context counter ("{series}-{year}-{seq}"), the PDF is
 * kept privately with its sha256 and made again from the snapshot when
 * the file is missing. The QR code leads to the public verification page.
 */
final class CertificateService
{
    public const MAX_ISSUE = 50;
    public const STATUS_PUBLISHED = 3;
    private const SETTING_SERIES = 'certificateSeries';
    private const K = 'plugins.generic.conferenceManager.certificate.';

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly AttendanceRepository $attendance,
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
        private readonly EditorialDirectory $editorial,
        private readonly InstitutionProfileService $institutions,
        private readonly ?PdfRenderer $pdf,
        private readonly ?ProofStore $files,
        /** directory with "certificate.{language}.html" */
        private readonly string $builtInDir,
        /** @var \Closure(ContextInfo, string): string uuid → verification URL */
        private readonly \Closure $verifyUrl,
        private readonly ?SettingsStore $settings = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function builtIn(string $locale): string
    {
        $lang = strtolower(substr($locale, 0, 2));
        foreach ([$lang, 'en'] as $l) {
            $file = rtrim($this->builtInDir, '/') . '/certificate.' . $l . '.html';
            if (preg_match('/^[a-z]{2}$/', $l) && is_file($file)) {
                return (string) file_get_contents($file);
            }
        }
        return '';
    }

    /** @return list<array<string, mixed>> */
    public function templates(ContextInfo $context, string $locale): array
    {
        $out = [];
        foreach (CertificateType::cases() as $type) {
            $active = $this->certificates->activeTemplate($context->id, $type->value);
            $out[] = [
                'type' => $type->value,
                'label' => $this->t($type->labelKey(), [], $locale),
                'custom' => $active !== null && !$active['builtIn'] ? $active['html'] : null,
                'version' => $active['version'] ?? 0,
                'builtIn' => $this->builtIn($context->primaryLocale),
            ];
        }
        return $out;
    }

    /** Empty html returns the type to the built-in template. Null for an unknown type. */
    public function saveTemplate(ContextInfo $context, string $type, string $html, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $t = CertificateType::tryFrom($type);
        if ($t === null) {
            return null;
        }
        $html = trim($html);
        $errors = $html === '' ? new ValidationErrors() : CertificateDocument::validate($html);
        if ($errors->isEmpty()) {
            $id = $this->certificates->saveTemplate($context->id, $t->value, $html === '' ? null : $html, $this->builtIn($context->primaryLocale), $now);
            $this->audit?->log($context->id, $actorId, 'certificate_template', $id, $html === '' ? 'reset' : 'update', null, ['type' => $t->value, 'sha256' => hash('sha256', $html)]);
        }
        return $errors;
    }

    /**
     * The template filled with sample values, for the editor.
     *
     * @return array{0: ?string, 1: ValidationErrors}|null
     */
    public function preview(ContextInfo $context, string $type, string $html, string $locale): ?array
    {
        $t = CertificateType::tryFrom($type);
        if ($t === null) {
            return null;
        }
        $html = trim($html) === '' ? $this->builtIn($context->primaryLocale) : $html;
        $errors = CertificateDocument::validate($html);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $profile = $this->institutions->getOrDraft($context);
        $signer = $profile->signerFor($t->documentType());
        $doc = $context->primaryLocale;
        $values = [
            'institutionName' => (string) $profile->text('name')->resolve($doc, $context->primaryLocale),
            'institutionShortName' => (string) $profile->text('shortName')->resolve($doc, $context->primaryLocale),
            'recipientName' => $this->t(self::K . 'sample.name', [], $doc),
            'recipientAffiliation' => $this->t(self::K . 'sample.affiliation', [], $doc),
            'certificateTitle' => $this->t(self::K . 'title.' . $t->value, [], $doc),
            'roleText' => $this->t(self::K . 'roleText.' . $t->value, [], $doc),
            'conferenceName' => $this->t(self::K . 'sample.conference', [], $doc),
            'eventDates' => '20.05.2027 – 21.05.2027',
            'venue' => $this->t(self::K . 'sample.venue', [], $doc),
            'eventFormat' => '',
            'paperTitle' => $t === CertificateType::Speaker ? $this->t(self::K . 'sample.paper', [], $doc) : '',
            'presentationType' => '',
            'hours' => '',
            'number' => CertificateNumber::format($this->series($context), 2027, 1),
            'issueDate' => '21.05.2027',
            'verifyUrl' => ($this->verifyUrl)($context, '00000000-0000-4000-8000-000000000000'),
            'signerName' => $signer === null ? '' : (string) $signer->fullName->resolve($doc, $context->primaryLocale),
            'signerPosition' => $signer === null ? '' : (string) $signer->position->resolve($doc, $context->primaryLocale),
            'contextName' => (string) (new LocalizedText($context->name))->resolve($doc, $context->primaryLocale),
        ];
        return [CertificateDocument::render($html, $values), $errors];
    }

    public function series(ContextInfo $context): string
    {
        return CertificateNumber::series((string) $this->settings?->get($context->id, self::SETTING_SERIES));
    }

    public function saveSeries(ContextInfo $context, string $series, ?int $actorId): ValidationErrors
    {
        $errors = new ValidationErrors();
        if (!CertificateNumber::isValidSeries($series)) {
            $errors->add('series', self::K . 'error.series');
            return $errors;
        }
        $this->settings?->set($context->id, self::SETTING_SERIES, CertificateNumber::series($series));
        $this->audit?->log($context->id, $actorId, 'certificate_settings', null, 'update', null, ['series' => CertificateNumber::series($series)]);
        return $errors;
    }

    /**
     * CRT-05: every participation of the edition with the certificate it
     * gets, or why it gets none.
     *
     * @return list<array<string, mixed>>|null
     */
    public function candidates(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null) {
            return null;
        }
        $people = $this->attendance->ofEdition($context->id, $editionId, $locale);
        $types = [];
        foreach ($this->registrations->registeredOfEdition($editionId) as $r) {
            $types[$r->submissionId] = $r->presentationType;
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $p): ?int => $p['submissionId'], $people))));
        $summaries = $ids === [] ? [] : $this->editorial->summaries($ids, $locale);
        $issued = [];
        foreach ($this->certificates->ofEdition($context->id, $editionId) as $c) {
            if ($c['revokedAt'] === null) {
                $issued[$c['participantId'] . '/' . $c['type']] = $c;
            }
        }
        $out = [];
        foreach ($people as $p) {
            $role = ParticipantRole::tryFrom($p['role']) ?? ParticipantRole::Listener;
            $summary = $p['submissionId'] === null ? null : ($summaries[$p['submissionId']] ?? null);
            $accepted = $summary === null ? null : ($summary['status'] !== EditorialDirectory::STATUS_DECLINED && $summary['stageId'] >= EditorialDirectory::STAGE_EDITING);
            [$type, $reason] = CertificateRules::decide(
                $role,
                $p['status'] === ParticipationStatus::Active->value,
                $p['attended'],
                ParticipationForm::tryFrom($p['form']) ?? ParticipationForm::InPerson,
                $p['submissionId'] === null ? null : ($types[$p['submissionId']] ?? null),
                $accepted,
                $summary !== null && $summary['status'] === self::STATUS_PUBLISHED,
            );
            $done = $type === null ? null : ($issued[$p['participantId'] . '/' . $type->value] ?? null);
            $out[] = [
                'participationId' => $p['participationId'],
                'participantId' => $p['participantId'],
                'name' => $p['name'],
                'email' => $p['email'],
                'role' => $role->value,
                'roleLabel' => $this->t($role->labelKey(), [], $locale),
                'attended' => $p['attended'],
                'minutes' => $p['minutes'],
                'paperTitle' => $summary['title'] ?? '',
                'type' => $type?->value,
                'typeLabel' => $type === null ? '' : $this->t($type->labelKey(), [], $locale),
                'reason' => $reason,
                'reasonLabel' => $reason === null ? '' : $this->t(self::K . 'reason.' . $reason, [], $locale),
                'issued' => $done === null ? null : ['id' => $done['id'], 'number' => $done['number']],
            ];
        }
        return $out;
    }

    /**
     * Issues certificates to the chosen participations that are eligible
     * and have none of that type yet.
     *
     * @param list<mixed> $participationIds
     * @return list<array{participationId: int, status: string, number?: string, id?: int, reason?: string}>|null
     */
    public function issue(ContextInfo $context, int $editionId, array $participationIds, ?int $actorId, DateTimeImmutable $now, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        $candidates = $edition === null ? null : $this->candidates($context, $editionId, $context->primaryLocale);
        if ($edition === null || $candidates === null) {
            return null;
        }
        $wanted = array_slice(array_values(array_unique(array_map('intval', $participationIds))), 0, self::MAX_ISSUE);
        $byId = [];
        foreach ($candidates as $c) {
            $byId[$c['participationId']] = $c;
        }
        $profile = $this->institutions->getOrDraft($context);
        $doc = $context->primaryLocale;
        $templates = [];
        $results = [];
        $done = [];
        $affiliations = null;
        foreach ($wanted as $pid) {
            $c = $byId[$pid] ?? null;
            if ($c === null) {
                $results[] = ['participationId' => $pid, 'status' => 'skipped', 'reason' => 'notFound'];
                continue;
            }
            $type = CertificateType::tryFrom((string) $c['type']);
            $key = $c['participantId'] . '/' . $c['type'];
            if ($type === null || $c['issued'] !== null || isset($done[$key])) {
                $results[] = ['participationId' => $pid, 'status' => 'skipped', 'reason' => $type === null ? (string) $c['reason'] : 'alreadyIssued'];
                continue;
            }
            $templates[$type->value] ??= $this->templateFor($context, $type, $now);
            $template = $templates[$type->value];
            $signer = $profile->signerFor($type->documentType());
            $uuid = self::uuid();
            $values = [
                'institutionName' => (string) $profile->text('name')->resolve($doc, $context->primaryLocale),
                'institutionShortName' => (string) $profile->text('shortName')->resolve($doc, $context->primaryLocale),
                'recipientName' => $c['name'] !== '' ? $c['name'] : $c['email'],
                'recipientAffiliation' => '',
                'certificateTitle' => $this->t(self::K . 'title.' . $type->value, [], $doc),
                'roleText' => $this->t(self::K . 'roleText.' . $type->value, [], $doc),
                'conferenceName' => (string) $edition->text('name')->resolve($doc, $context->primaryLocale),
                'eventDates' => self::dates($edition),
                'venue' => (string) $edition->text('venue')->resolve($doc, $context->primaryLocale),
                'eventFormat' => $this->t($edition->format->labelKey(), [], $doc),
                'paperTitle' => in_array($type, [CertificateType::Speaker, CertificateType::Participation], true) ? (string) $c['paperTitle'] : '',
                'presentationType' => '',
                'hours' => $edition->hours === null ? '' : (string) $edition->hours,
                'issueDate' => $now->setTimezone(new DateTimeZone($edition->timezone))->format('d.m.Y'),
                'verifyUrl' => ($this->verifyUrl)($context, $uuid),
                'signerName' => $signer === null ? '' : (string) $signer->fullName->resolve($doc, $context->primaryLocale),
                'signerPosition' => $signer === null ? '' : (string) $signer->position->resolve($doc, $context->primaryLocale),
                'contextName' => (string) (new LocalizedText($context->name))->resolve($doc, $context->primaryLocale),
            ];
            if ($affiliations === null) {
                $affiliations = [];
                foreach ($this->attendance->ofEdition($context->id, $editionId, $doc) as $p) {
                    $affiliations[$p['participationId']] = $p['affiliation'];
                }
            }
            $values['recipientAffiliation'] = $affiliations[$pid] ?? '';
            $created = $this->certificates->create($context->id, $this->series($context), [
                'uuid' => $uuid,
                'edition_id' => $editionId,
                'participant_id' => $c['participantId'],
                'template_id' => $template['id'],
                'template_version' => $template['version'],
                'type' => $type->value,
                'hours' => $edition->hours,
                'snapshot' => ['locale' => $doc, 'html' => $template['html'], 'values' => $values, 'participationId' => $pid],
            ], $now);
            $this->storePdf($context, $created['id']);
            $this->audit?->log($context->id, $actorId, 'certificate', $created['id'], 'issued', null, ['number' => $created['number'], 'type' => $type->value, 'participationId' => $pid]);
            $done[$key] = true;
            $results[] = ['participationId' => $pid, 'status' => 'issued', 'id' => $created['id'], 'number' => $created['number']];
        }
        return $results;
    }

    /** @return list<array<string, mixed>>|null */
    public function ofEdition(ContextInfo $context, int $editionId, string $locale): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        return array_map(fn (array $c): array => $this->present($context, $c, $locale), $this->certificates->ofEdition($context->id, $editionId));
    }

    /**
     * Certificates of the participants that belong to the person (cabinet).
     *
     * @param list<int> $participantIds
     * @return list<array<string, mixed>>
     */
    public function mine(ContextInfo $context, array $participantIds, string $locale): array
    {
        $out = [];
        foreach ($this->certificates->ofParticipants($participantIds) as $c) {
            if ($c['contextId'] === $context->id && $c['revokedAt'] === null) {
                $out[] = $this->present($context, $c, $locale);
            }
        }
        return $out;
    }

    /**
     * The PDF; with $ownerParticipantIds only for those participants.
     *
     * @param list<int>|null $ownerParticipantIds
     * @return array{name: string, content: string}|null
     */
    public function pdf(ContextInfo $context, int $id, ?array $ownerParticipantIds = null): ?array
    {
        $c = $this->certificates->find($context->id, $id);
        if ($c === null || ($ownerParticipantIds !== null && !in_array($c['participantId'], $ownerParticipantIds, true))) {
            return null;
        }
        $content = $c['pdfPath'] === null ? null : $this->files?->read($c['pdfPath']);
        if ($content === null || ($c['pdfSha256'] !== null && hash('sha256', $content) !== $c['pdfSha256'])) {
            $content = $this->render($c);
            if ($content === null) {
                return null;
            }
        }
        return ['name' => 'certificate-' . preg_replace('/[^\p{L}\p{N}-]/u', '', $c['number']) . '.pdf', 'content' => $content];
    }

    /** The HTML of an issued certificate, from its snapshot. */
    /** @param array<string, mixed> $certificate */
    public function html(array $certificate): string
    {
        $s = $certificate['snapshot'];
        $values = (array) ($s['values'] ?? []);
        $values['number'] = (string) ($s['number'] ?? $certificate['number']);
        return CertificateDocument::render((string) ($s['html'] ?? ''), array_map('strval', $values));
    }

    /**
     * CRT-07: what the public verification page shows, or null.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $uuid, string $locale): ?array
    {
        $c = $this->certificates->findByUuid(strtolower(trim($uuid)));
        if ($c === null) {
            return null;
        }
        $v = (array) ($c['snapshot']['values'] ?? []);
        $type = CertificateType::tryFrom($c['type']);
        $replacement = $c['replacedById'] === null ? null : $this->certificates->find($c['contextId'], $c['replacedById']);
        return [
            'valid' => $c['revokedAt'] === null,
            'number' => $c['number'],
            'type' => $type === null ? $c['type'] : $this->t($type->labelKey(), [], $locale),
            'recipientName' => (string) ($v['recipientName'] ?? ''),
            'conferenceName' => (string) ($v['conferenceName'] ?? ''),
            'eventDates' => (string) ($v['eventDates'] ?? ''),
            'institutionName' => (string) ($v['institutionName'] ?? ''),
            'paperTitle' => (string) ($v['paperTitle'] ?? ''),
            'hours' => (string) ($v['hours'] ?? ''),
            'issueDate' => (string) ($v['issueDate'] ?? ''),
            'revokedAt' => $c['revokedAt'],
            'revokeReason' => $c['revokeReason'],
            'replacedBy' => $replacement['number'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function present(ContextInfo $context, array $c, string $locale): array
    {
        $type = CertificateType::tryFrom($c['type']);
        $v = (array) ($c['snapshot']['values'] ?? []);
        return [
            'id' => $c['id'],
            'uuid' => $c['uuid'],
            'number' => $c['number'],
            'type' => $c['type'],
            'typeLabel' => $type === null ? $c['type'] : $this->t($type->labelKey(), [], $locale),
            'participantId' => $c['participantId'],
            'recipientName' => (string) ($v['recipientName'] ?? ''),
            'conferenceName' => (string) ($v['conferenceName'] ?? ''),
            'issuedAt' => $c['issuedAt'],
            'revokedAt' => $c['revokedAt'],
            'sha256' => $c['pdfSha256'],
            'verifyUrl' => ($this->verifyUrl)($context, $c['uuid']),
        ];
    }

    /** @return array{id: int, version: int, html: string} */
    private function templateFor(ContextInfo $context, CertificateType $type, DateTimeImmutable $now): array
    {
        $active = $this->certificates->activeTemplate($context->id, $type->value);
        if ($active !== null && !$active['builtIn']) {
            return ['id' => $active['id'], 'version' => $active['version'], 'html' => $active['html']];
        }
        $html = $this->builtIn($context->primaryLocale);
        $row = $this->certificates->builtInTemplate($context->id, $type->value, $html, $now);
        return ['id' => $row['id'], 'version' => $row['version'], 'html' => $html];
    }

    private function storePdf(ContextInfo $context, int $id): void
    {
        $c = $this->certificates->find($context->id, $id);
        if ($c !== null) {
            $this->render($c);
        }
    }

    /**
     * Renders the PDF from the snapshot and keeps it.
     *
     * @param array<string, mixed> $c
     */
    private function render(array $c): ?string
    {
        if ($this->pdf === null) {
            return null;
        }
        $content = $this->pdf->render($this->html($c), $c['number'], true);
        $sha = hash('sha256', $content);
        $key = $c['contextId'] . '/' . $c['editionId'] . '/' . str_replace('-', '', $c['uuid']) . '-' . substr($sha, 0, 16) . '.pdf';
        if ($this->files !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'cmcert');
            if ($tmp !== false) {
                file_put_contents($tmp, $content);
                if ($this->files->put($tmp, $key)) {
                    if ($c['pdfPath'] !== null && $c['pdfPath'] !== $key) {
                        $this->files->remove($c['pdfPath']);
                    }
                    $this->certificates->setPdf($c['id'], $key, $sha);
                }
                @unlink($tmp);
            }
        }
        return $content;
    }

    private static function dates(Edition $edition): string
    {
        $tz = new DateTimeZone($edition->timezone);
        $start = $edition->date('startsAt')?->setTimezone($tz);
        $end = $edition->date('endsAt')?->setTimezone($tz);
        if ($start === null) {
            return '';
        }
        if ($end === null || $start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('d.m.Y');
        }
        return $start->format('d.m.Y') . ' – ' . $end->format('d.m.Y');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @param array<string, mixed> $params */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, $params, $locale);
    }
}
