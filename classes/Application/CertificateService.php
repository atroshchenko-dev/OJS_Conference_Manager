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
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionProfile;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Certificates (CRT-01…11, JUR-01…04).
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
    private const SETTING_VECTOR = 'vectorEnabled';
    public const REASON_MAX = 500;
    public const BATCH_SIZE = 25;
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

    /** The built-in template: "certificate-{type}.{language}.html" when there is one, else "certificate.{language}.html". */
    public function builtIn(string $locale, ?CertificateType $type = null): string
    {
        $lang = strtolower(substr($locale, 0, 2));
        $dir = rtrim($this->builtInDir, '/');
        foreach ([$lang, 'en'] as $l) {
            if (!preg_match('/^[a-z]{2}$/', $l)) {
                continue;
            }
            foreach (array_filter([$type === null ? null : 'certificate-' . $type->value . '.' . $l . '.html', 'certificate.' . $l . '.html']) as $name) {
                if (is_file($dir . '/' . $name)) {
                    return (string) file_get_contents($dir . '/' . $name);
                }
            }
        }
        return '';
    }

    /** CRT-03: the jurisdiction of the context gives professional development documents. */
    public function professionalDevelopmentAllowed(ContextInfo $context): bool
    {
        return in_array(JurisdictionProfile::FEATURE_PK_CERTIFICATE, $this->institutions->features($context), true);
    }

    /** CRT-10: the "Vector" export is on: the jurisdiction has it and the journal turned it on. */
    public function vectorEnabled(ContextInfo $context): bool
    {
        return in_array(JurisdictionProfile::FEATURE_VECTOR, $this->institutions->features($context), true)
            && filter_var($this->settings?->get($context->id, self::SETTING_VECTOR), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<CertificateType> */
    private function types(ContextInfo $context): array
    {
        $pk = $this->professionalDevelopmentAllowed($context);
        $out = [];
        foreach (CertificateType::cases() as $t) {
            if ($pk || $t !== CertificateType::ProfessionalDevelopment) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function templates(ContextInfo $context, string $locale): array
    {
        $out = [];
        foreach ($this->types($context) as $type) {
            $active = $this->certificates->activeTemplate($context->id, $type->value);
            $out[] = [
                'type' => $type->value,
                'label' => $this->t($type->labelKey(), [], $locale),
                'custom' => $active !== null && !$active['builtIn'] ? $active['html'] : null,
                'version' => $active['version'] ?? 0,
                'builtIn' => $this->builtIn($context->primaryLocale, $type),
            ];
        }
        return $out;
    }

    /** Empty html returns the type to the built-in template. Null for an unknown type. */
    public function saveTemplate(ContextInfo $context, string $type, string $html, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $t = CertificateType::tryFrom($type);
        if ($t === null || !in_array($t, $this->types($context), true)) {
            return null;
        }
        $html = trim($html);
        $errors = $html === '' ? new ValidationErrors() : CertificateDocument::validate($html);
        if ($errors->isEmpty()) {
            $id = $this->certificates->saveTemplate($context->id, $t->value, $html === '' ? null : $html, $this->builtIn($context->primaryLocale, $t), $now);
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
        $html = trim($html) === '' ? $this->builtIn($context->primaryLocale, $t) : $html;
        $errors = CertificateDocument::validate($html);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $doc = $context->primaryLocale;
        $values = $this->values($context, $this->institutions->getOrDraft($context), $t, $doc, null, [
            'name' => $this->t(self::K . 'sample.name', [], $doc),
            'affiliation' => $this->t(self::K . 'sample.affiliation', [], $doc),
            'paperTitle' => $this->t(self::K . 'sample.paper', [], $doc),
        ], new DateTimeImmutable('2027-05-21T12:00:00Z'), '00000000-0000-4000-8000-000000000000');
        $values['number'] = CertificateNumber::format($this->series($context), 2027, 1);
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
        $pk = ($edition->hours ?? 0) > 0 && $this->professionalDevelopmentAllowed($context);
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
                $pk,
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
            $uuid = self::uuid();
            if ($affiliations === null) {
                $affiliations = [];
                foreach ($this->attendance->ofEdition($context->id, $editionId, $doc) as $p) {
                    $affiliations[$p['participationId']] = $p['affiliation'];
                }
            }
            $person = ['name' => $c['name'] !== '' ? $c['name'] : $c['email'], 'affiliation' => $affiliations[$pid] ?? '', 'paperTitle' => (string) $c['paperTitle']];
            $values = $this->values($context, $profile, $type, $doc, $edition, $person, $now, $uuid);
            $snapshot = ['locale' => $doc, 'html' => $template['html'], 'values' => $values, 'participationId' => $pid, 'email' => (string) $c['email']];
            // JUR-04: an English version the participant may choose, from the built-in English template.
            if (strtolower(substr($doc, 0, 2)) !== 'en') {
                $snapshot['alt'] = ['locale' => 'en', 'html' => $this->builtIn('en', $type), 'values' => $this->values($context, $profile, $type, 'en', $edition, $person, $now, $uuid)];
            }
            $created = $this->certificates->create($context->id, $this->series($context), [
                'uuid' => $uuid,
                'edition_id' => $editionId,
                'participant_id' => $c['participantId'],
                'template_id' => $template['id'],
                'template_version' => $template['version'],
                'type' => $type->value,
                'hours' => $edition->hours,
                'snapshot' => $snapshot,
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
    public function pdf(ContextInfo $context, int $id, ?array $ownerParticipantIds = null, ?string $language = null): ?array
    {
        $c = $this->certificates->find($context->id, $id);
        if ($c === null || ($ownerParticipantIds !== null && !in_array($c['participantId'], $ownerParticipantIds, true))) {
            return null;
        }
        $alt = $c['snapshot']['alt'] ?? null;
        if ($language !== null && is_array($alt) && ($alt['locale'] ?? '') === $language && $this->pdf !== null) {
            // JUR-04: the other language is made from the snapshot on request.
            $html = CertificateDocument::render((string) ($alt['html'] ?? ''), array_map('strval', ['number' => $c['number']] + (array) ($alt['values'] ?? [])));
            return ['name' => 'certificate-' . preg_replace('/[^\p{L}\p{N}-]/u', '', $c['number']) . '-' . $language . '.pdf', 'content' => $this->pdf->render($html, $c['number'], true)];
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
     * CRT-09: revokes a certificate with a reason. Null when there is no such
     * certificate.
     */
    public function revoke(ContextInfo $context, int $id, string $reason, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $c = $this->certificates->find($context->id, $id);
        if ($c === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            $errors->add('reason', self::K . 'error.reason');
        } elseif ($c['revokedAt'] !== null || !$this->certificates->revoke($id, $reason, null, $now)) {
            $errors->add('id', self::K . 'error.revoked');
        } else {
            $this->audit?->log($context->id, $actorId, 'certificate', $id, 'revoked', null, ['number' => $c['number'], 'reason' => $reason]);
        }
        return $errors;
    }

    /**
     * CRT-09: a new certificate with a new number from today's data; the old
     * one is revoked (if it is not yet) and points to the new one.
     *
     * @return array{0: ?array{id: int, number: string}, 1: ValidationErrors}|null
     */
    public function reissue(ContextInfo $context, int $id, string $reason, ?int $actorId, DateTimeImmutable $now): ?array
    {
        $c = $this->certificates->find($context->id, $id);
        if ($c === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $reason = trim($reason) === '' ? $this->t(self::K . 'reissuedReason', [], $context->primaryLocale) : trim($reason);
        if (mb_strlen($reason) > self::REASON_MAX) {
            $errors->add('reason', self::K . 'error.reason');
            return [null, $errors];
        }
        if ($c['replacedById'] !== null) {
            $errors->add('id', self::K . 'error.replaced');
            return [null, $errors];
        }
        $pid = (int) ($c['snapshot']['participationId'] ?? 0);
        // The person must still be eligible, else the old document stays valid.
        $candidate = null;
        foreach ($this->candidates($context, $c['editionId'], $context->primaryLocale) ?? [] as $row) {
            if ($row['participationId'] === $pid) {
                $candidate = $row;
            }
        }
        if ($candidate === null || $candidate['type'] === null) {
            $errors->add('id', self::K . 'reason.' . ($candidate['reason'] ?? 'notFound'));
            return [null, $errors];
        }
        if ($c['revokedAt'] === null) {
            $this->certificates->revoke($id, $reason, null, $now);
        }
        $out = $this->issue($context, $c['editionId'], [$pid], $actorId, $now, $context->primaryLocale) ?? [];
        $new = $out[0] ?? null;
        if ($new === null || $new['status'] !== 'issued' || !isset($new['id'], $new['number'])) {
            $errors->add('id', self::K . 'reason.' . ($new['reason'] ?? 'notFound'));
            return [null, $errors];
        }
        $this->certificates->setReplacedBy($id, (int) $new['id']);
        $this->audit?->log($context->id, $actorId, 'certificate', $id, 'reissued', null, ['number' => $c['number'], 'newNumber' => $new['number'], 'reason' => $reason]);
        return [['id' => (int) $new['id'], 'number' => (string) $new['number']], $errors];
    }

    /**
     * CRT-08, JUR-02: the public list of professional development documents.
     *
     * @return array{items: list<array<string, mixed>>, itemsMax: int}
     */
    public function publicList(ContextInfo $context, int $page, int $perPage = 50): array
    {
        if (!$this->professionalDevelopmentAllowed($context)) {
            return ['items' => [], 'itemsMax' => 0];
        }
        $out = $this->certificates->publicList($context->id, CertificateType::ProfessionalDevelopment->value, $perPage, max(0, $page - 1) * $perPage);
        $items = [];
        foreach ($out['items'] as $c) {
            $v = (array) ($c['snapshot']['values'] ?? []);
            $items[] = [
                'number' => $c['number'],
                'recipientName' => (string) ($v['recipientName'] ?? ''),
                'topic' => (string) ($v['conferenceName'] ?? ''),
                'eventType' => (string) ($v['eventType'] ?? ''),
                'eventFormat' => (string) ($v['eventFormat'] ?? ''),
                'eventDates' => (string) ($v['eventDates'] ?? ''),
                'hours' => (string) ($v['hours'] ?? ''),
                'ects' => (string) ($v['ects'] ?? ''),
                'issueDate' => (string) ($v['issueDate'] ?? ''),
                'verifyUrl' => ($this->verifyUrl)($context, $c['uuid']),
            ];
        }
        return ['items' => $items, 'itemsMax' => $out['itemsMax']];
    }

    public function setVectorEnabled(ContextInfo $context, bool $on, ?int $actorId): void
    {
        $this->settings?->set($context->id, self::SETTING_VECTOR, $on);
        $this->audit?->log($context->id, $actorId, 'certificate_settings', null, 'update', null, ['vectorEnabled' => $on]);
    }

    /**
     * CRT-10, JUR-03: rows of professional development documents of an
     * edition for the "Vector" platform, marked as exported. Without
     * $all only those not exported yet. Null when the edition is missing.
     *
     * @return list<array<string, string>>|null
     */
    public function vectorExport(ContextInfo $context, int $editionId, bool $all, ?int $actorId, DateTimeImmutable $now): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null) {
            return null;
        }
        $rows = [];
        $ids = [];
        foreach ($this->certificates->forExport($context->id, $editionId, CertificateType::ProfessionalDevelopment->value, !$all) as $c) {
            $v = (array) ($c['snapshot']['values'] ?? []);
            $rows[] = [
                'number' => $c['number'],
                'issueDate' => (string) ($v['issueDate'] ?? ''),
                'recipientName' => (string) ($v['recipientName'] ?? ''),
                'email' => (string) ($c['snapshot']['email'] ?? ''),
                'programId' => (string) ($edition->vectorProgramId ?? ''),
                'topic' => (string) ($v['conferenceName'] ?? ''),
                'eventType' => (string) ($v['eventType'] ?? ''),
                'eventFormat' => (string) ($v['eventFormat'] ?? ''),
                'eventDates' => (string) ($v['eventDates'] ?? ''),
                'hours' => (string) ($v['hours'] ?? ''),
                'ects' => (string) ($v['ects'] ?? ''),
                'verifyUrl' => ($this->verifyUrl)($context, $c['uuid']),
            ];
            $ids[] = $c['id'];
        }
        $this->certificates->markExported($ids, $now);
        $this->audit?->log($context->id, $actorId, 'certificate', null, 'vector_export', null, ['editionId' => $editionId, 'rows' => count($rows), 'all' => $all]);
        return $rows;
    }

    /**
     * CRT-10: the export as a CSV file (UTF-8 with BOM, ";") with headers in
     * the interface language; null when the edition is unknown.
     */
    public function vectorCsv(ContextInfo $context, int $editionId, bool $all, ?int $actorId, DateTimeImmutable $now, string $locale): ?string
    {
        $rows = $this->vectorExport($context, $editionId, $all, $actorId, $now);
        if ($rows === null) {
            return null;
        }
        $columns = ['number', 'issueDate', 'recipientName', 'email', 'programId', 'topic', 'eventType', 'eventFormat', 'eventDates', 'hours', 'ects', 'verifyUrl'];
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return null;
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn (string $c): string => $this->t(self::K . 'vector.column.' . $c, [], $locale), $columns), ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (string $c): string => ParticipantService::safeCell($row[$c]), $columns), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * CRT-06: issues to everyone eligible who has no certificate yet, or to
     * the chosen participations. Up to one portion is issued at once; more
     * go to the queue in portions of BATCH_SIZE.
     *
     * @param list<mixed>|null $participationIds null: everyone eligible
     * @return array{eligible: int, issued: int, queued: int}|null
     */
    public function issueBatch(ContextInfo $context, int $editionId, ?array $participationIds, CertificateQueue $queue, ?int $actorId, DateTimeImmutable $now, string $locale): ?array
    {
        $candidates = $this->candidates($context, $editionId, $locale);
        if ($candidates === null) {
            return null;
        }
        $chosen = $participationIds === null ? null : array_flip(array_map('intval', array_filter($participationIds, 'is_numeric')));
        $ids = [];
        foreach ($candidates as $c) {
            if ($c['type'] !== null && $c['issued'] === null && ($chosen === null || isset($chosen[$c['participationId']]))) {
                $ids[] = $c['participationId'];
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) <= self::BATCH_SIZE) {
            $done = $ids === [] ? [] : ($this->issue($context, $editionId, $ids, $actorId, $now, $locale) ?? []);
            $issued = count(array_filter($done, static fn (array $r): bool => $r['status'] === 'issued'));
            return ['eligible' => count($ids), 'issued' => $issued, 'queued' => 0];
        }
        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $queue->push($context->id, $editionId, $chunk, $actorId, $locale);
        }
        $this->audit?->log($context->id, $actorId, 'edition', $editionId, 'certificates_queued', null, ['count' => count($ids)]);
        return ['eligible' => count($ids), 'issued' => 0, 'queued' => count($ids)];
    }

    /**
     * CRT-11: adds as reviewers of the edition everyone who completed a
     * review of its registered submissions, so they get the thank-you letter.
     *
     * @return array{found: int, added: int}|null
     */
    public function addReviewers(ContextInfo $context, int $editionId, ?int $actorId, DateTimeImmutable $now, string $locale): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $ids = array_values(array_unique(array_map(static fn ($r): int => $r->submissionId, $this->registrations->registeredOfEdition($editionId))));
        $reviewers = $this->editorial->completedReviewers($ids, $locale);
        $added = 0;
        foreach ($reviewers as $r) {
            $res = $this->attendance->addStaff($context->id, $editionId, ParticipantRole::Reviewer->value, [
                'email' => $r['email'],
                'userId' => $r['userId'],
                'givenName' => $r['givenName'],
                'familyName' => $r['familyName'],
                'affiliation' => $r['affiliation'],
                'locale' => $locale,
            ], $now);
            $added += $res['created'] ? 1 : 0;
        }
        $this->audit?->log($context->id, $actorId, 'edition', $editionId, 'reviewers_added', null, ['found' => count($reviewers), 'added' => $added]);
        return ['found' => count($reviewers), 'added' => $added];
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
            'revokeReason' => $c['revokeReason'],
            'replacedById' => $c['replacedById'],
            'languages' => array_values(array_filter([(string) ($c['snapshot']['locale'] ?? ''), (string) ($c['snapshot']['alt']['locale'] ?? '')])),
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
        $html = $this->builtIn($context->primaryLocale, $type);
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

    /**
     * Values of the template variables in one language.
     *
     * @param array{name: string, affiliation: string, paperTitle: string} $person
     * @return array<string, string>
     */
    private function values(ContextInfo $context, InstitutionProfile $profile, CertificateType $type, string $doc, ?Edition $edition, array $person, DateTimeImmutable $now, string $uuid): array
    {
        $p = $context->primaryLocale;
        $signer = $profile->signerFor($type->documentType());
        $text = static fn (string $field): string => $edition === null ? '' : (string) $edition->text($field)->resolve($doc, $p);
        $hours = $edition?->hours;
        // ECTS: as set on the edition, else hours / 30 (п. 13 Постанови №800).
        $ects = $edition?->ectsX100 !== null ? $edition->ectsX100 / 100 : ($hours !== null && $hours > 0 ? $hours / 30 : null);
        return [
            'institutionName' => (string) $profile->text('name')->resolve($doc, $p),
            'institutionShortName' => (string) $profile->text('shortName')->resolve($doc, $p),
            'institutionLegalId' => (string) $profile->legalId,
            'recipientName' => $person['name'],
            'recipientAffiliation' => $person['affiliation'],
            'certificateTitle' => $this->t(self::K . 'title.' . $type->value, [], $doc),
            'roleText' => $this->t(self::K . 'roleText.' . $type->value, [], $doc),
            'conferenceName' => $edition === null ? $this->t(self::K . 'sample.conference', [], $doc) : $text('name'),
            'eventDates' => $edition === null ? '20.05.2027 – 21.05.2027' : self::dates($edition),
            'venue' => $edition === null ? $this->t(self::K . 'sample.venue', [], $doc) : $text('venue'),
            'eventFormat' => $edition === null ? '' : $this->t($edition->format->labelKey(), [], $doc),
            'eventType' => $edition === null ? '' : $this->t($edition->eventType->labelKey(), [], $doc),
            'paperTitle' => in_array($type, [CertificateType::Speaker, CertificateType::Participation], true) ? $person['paperTitle'] : '',
            'presentationType' => '',
            'hours' => $hours === null ? ($edition === null ? '6' : '') : (string) $hours,
            'ects' => $ects === null ? ($edition === null ? '0,2' : '') : str_replace('.', ',', rtrim(rtrim(number_format($ects, 2, '.', ''), '0'), '.')),
            'learningOutcomes' => $edition === null ? $this->t(self::K . 'sample.outcomes', [], $doc) : $text('learningOutcomes'),
            'partialQualifications' => $text('partialQualifications') !== '' || $type !== CertificateType::ProfessionalDevelopment ? $text('partialQualifications') : '—',
            'issueDate' => $now->setTimezone(new DateTimeZone($edition->timezone ?? 'UTC'))->format('d.m.Y'),
            'verifyUrl' => ($this->verifyUrl)($context, $uuid),
            'signerName' => $signer === null ? '' : (string) $signer->fullName->resolve($doc, $p),
            'signerPosition' => $signer === null ? '' : (string) $signer->position->resolve($doc, $p),
            'contextName' => (string) (new LocalizedText($context->name))->resolve($doc, $p),
            'number' => '',
        ];
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
