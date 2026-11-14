<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionValidator;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;

/**
 * Use cases of editions: list, read, create, update, copy, delete
 * (EDN-01…05, EDN-09, EVT-01…03).
 */
final class EditionService
{
    private const K = 'plugins.generic.conferenceManager.validation.';

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly JurisdictionRegistry $jurisdictions,
        private readonly ?InstitutionRepository $institutions = null,
        private readonly ?IssueProvisioner $issues = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Zone of new editions: the default of the institution's jurisdiction
     * (UA: Europe/Kyiv), else the server zone of the installation.
     */
    public function defaultTimezone(ContextInfo $context): string
    {
        $code = $this->institutions?->findByContextId($context->id)->jurisdiction
            ?? $this->jurisdictions->defaultCodeForLocale($context->primaryLocale);
        $zone = $this->jurisdictions->has($code) ? $this->jurisdictions->get($code)->getDefaults()['timezone'] : null;
        if ($zone !== null && DateInput::isValidTimezone($zone)) {
            return $zone;
        }
        return DateInput::isValidTimezone($context->serverTimezone) ? $context->serverTimezone : 'UTC';
    }

    public function get(ContextInfo $context, int $id): ?Edition
    {
        return $this->editions->find($context->id, $id);
    }

    /**
     * @param list<EditionStatus> $statuses
     * @return array{items: list<Edition>, itemsMax: int}
     */
    public function list(ContextInfo $context, array $statuses = [], int $count = 20, int $offset = 0): array
    {
        $count = max(1, min(100, $count));
        return [
            'items' => $this->editions->list($context->id, $statuses, $count, max(0, $offset)),
            'itemsMax' => $this->editions->count($context->id, $statuses),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ?Edition, 1: ValidationErrors}
     */
    public function create(ContextInfo $context, array $input, ?int $actorUserId = null, ?string $ip = null): array
    {
        unset($input['issueId']);
        [$edition, $errors] = $this->build($context, $input, null);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $saved = $this->persist($context, $edition, null);
        $this->audit?->log($context->id, $actorUserId, 'edition', $saved->id, 'create', null, $saved->toArray(), $ip);
        return [$saved, $errors];
    }

    /**
     * Replaces the whole edition. Fields missing from the input take
     * their defaults, as with PUT of the institution profile.
     *
     * @param array<string, mixed> $input
     * @return array{0: ?Edition, 1: ValidationErrors}|null null when the edition is not found
     */
    public function update(ContextInfo $context, int $id, array $input, ?int $actorUserId = null, ?string $ip = null): ?array
    {
        $current = $this->editions->find($context->id, $id);
        if ($current === null) {
            return null;
        }
        // The OJS issue link is managed by the plugin, never by the client.
        $input['issueId'] = $current->issueId;
        [$edition, $errors] = $this->build($context, $input, $current);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $saved = $this->persist($context, $edition, $current);
        $this->audit?->log($context->id, $actorUserId, 'edition', $saved->id, 'update', $current->toArray(), $saved->toArray(), $ip);
        return [$saved, $errors];
    }

    /**
     * Copy of an edition with new path and dates (EDN-09, first part:
     * the edition itself; tariffs and templates follow with their modules).
     *
     * @param array<string, mixed> $overrides path, dates, name…
     * @return array{0: ?Edition, 1: ValidationErrors}|null
     */
    public function copy(ContextInfo $context, int $id, array $overrides, ?int $actorUserId = null, ?string $ip = null): ?array
    {
        $source = $this->editions->find($context->id, $id);
        if ($source === null) {
            return null;
        }
        return $this->create($context, array_merge($source->toCopyInput(), $overrides), $actorUserId, $ip);
    }

    /**
     * EDN-04: an edition with participants, invoices or submissions
     * cannot be deleted; archive it instead.
     *
     * @return ?ValidationErrors null when not found; empty errors when deleted
     */
    public function delete(ContextInfo $context, int $id, ?int $actorUserId = null, ?string $ip = null): ?ValidationErrors
    {
        $current = $this->editions->find($context->id, $id);
        if ($current === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if ($this->editions->isInUse($context->id, $id)) {
            $errors->add('id', self::K . 'editionInUse');
            return $errors;
        }
        $this->editions->delete($context->id, $id);
        $this->audit?->log($context->id, $actorUserId, 'edition', $id, 'delete', $current->toArray(), null, $ip);
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function validate(ContextInfo $context, array $input, ?int $id = null): ValidationErrors
    {
        $current = $id === null ? null : $this->editions->find($context->id, $id);
        return $this->build($context, $input, $current)[1];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: Edition, 1: ValidationErrors}
     */
    private function build(ContextInfo $context, array $input, ?Edition $current): array
    {
        $errors = new ValidationErrors();
        $input = $this->filterLocales($input, $context->formLocales);
        $edition = Edition::fromArray(
            $context->id,
            $input,
            $current?->id,
            $current->timezone ?? $this->defaultTimezone($context),
            $errors,
        );
        $errors->merge((new EditionValidator())->validate($edition, $context->primaryLocale, $current, $errors));

        if (!$errors->has('path') && $edition->path !== '') {
            $other = $this->editions->findByPath($context->id, $edition->path);
            if ($other !== null && $other->id !== $current?->id) {
                $errors->add('path', self::K . 'pathTaken');
            }
        }
        return [$edition, $errors];
    }

    private function persist(ContextInfo $context, Edition $edition, ?Edition $current): Edition
    {
        $saved = $this->editions->save($edition);
        // EDN-04: in the "ojs_issue" mode the edition gets its own unpublished
        // issue. Leaving that mode keeps the link, so returning to it does not
        // create a second issue; the issue is never deleted by the plugin.
        if ($saved->publicationMode === PublicationMode::OjsIssue && $this->issues !== null
            && ($saved->issueId === null || !$this->issues->exists($context->id, $saved->issueId))) {
            $year = $saved->date('startsAt') !== null ? (int) $saved->date('startsAt')->format('Y') : null;
            $issueId = $this->issues->createUnpublishedIssue($context->id, $saved->text('name')->toArray(), $year);
            $saved = $this->editions->save($saved->withIssueId($issueId));
        }
        return $saved;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $locales
     * @return array<string, mixed>
     */
    private function filterLocales(array $input, array $locales): array
    {
        if ($locales === []) {
            return $input;
        }
        $keep = array_flip($locales);
        foreach (Edition::LOCALIZED_FIELDS as $field) {
            if (isset($input[$field]) && is_array($input[$field])) {
                $input[$field] = array_intersect_key($input[$field], $keep);
            }
        }
        return $input;
    }
}
