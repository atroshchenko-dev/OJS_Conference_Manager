<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionValidator;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\Ownership;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;

/**
 * Use cases of the institution profile: read with defaults, save,
 * export and import (INS-01…07).
 */
final class InstitutionProfileService
{
    public const EXPORT_FORMAT = 'ojs-conferenceManager-institution';
    public const EXPORT_VERSION = 1;

    private const K = 'plugins.generic.conferenceManager.validation.';

    public function __construct(
        private readonly InstitutionRepository $repository,
        private readonly JurisdictionRegistry $jurisdictions,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Stored profile, or an unsaved draft prefilled from the context
     * (its name and contact) and the jurisdiction default for its locale.
     */
    public function getOrDraft(ContextInfo $context): InstitutionProfile
    {
        return $this->repository->findByContextId($context->id) ?? $this->draft($context);
    }

    /**
     * Features of the jurisdiction of the context (JurisdictionProfile::FEATURE_*).
     *
     * @return list<string>
     */
    public function features(ContextInfo $context): array
    {
        $code = $this->getOrDraft($context)->jurisdiction;
        return $this->jurisdictions->has($code) ? $this->jurisdictions->get($code)->getFeatures() : [];
    }

    /** The label of the institution's legal id in its jurisdiction (ЄДРПОУ in Ukraine). */
    public function legalIdLabelKey(ContextInfo $context): string
    {
        $code = $this->getOrDraft($context)->jurisdiction;
        return ($this->jurisdictions->has($code) ? $this->jurisdictions->get($code) : new \APP\plugins\generic\conferenceManager\classes\Jurisdiction\Generic\GenericProfile())->getLegalIdLabelKey();
    }

    public function exists(int $contextId): bool
    {
        return $this->repository->findByContextId($contextId) !== null;
    }

    public function draft(ContextInfo $context): InstitutionProfile
    {
        return new InstitutionProfile(
            null,
            $context->id,
            $this->jurisdictions->defaultCodeForLocale($context->primaryLocale),
            InstitutionType::University,
            Ownership::State,
            null,
            ['name' => new LocalizedText($context->name)],
            [],
            $context->contactEmail,
        );
    }

    /**
     * Validates and saves. Returns the saved profile, or the errors.
     *
     * @param array<string, mixed> $input
     * @return array{0: ?InstitutionProfile, 1: ValidationErrors}
     */
    public function save(ContextInfo $context, array $input, ?int $actorUserId = null, ?string $ip = null): array
    {
        $current = $this->repository->findByContextId($context->id);
        [$profile, $errors] = $this->build($context, $input, $current?->id);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }

        $saved = $this->repository->save($profile);
        $this->audit?->log(
            $context->id,
            $actorUserId,
            'institution',
            $saved->id,
            $current === null ? 'create' : 'update',
            $current?->toArray(),
            $saved->toArray(),
            $ip,
        );
        return [$saved, $errors];
    }

    /**
     * Validates without saving (used by forms to show errors in place).
     *
     * @param array<string, mixed> $input
     */
    public function validate(ContextInfo $context, array $input): ValidationErrors
    {
        return $this->build($context, $input, null)[1];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: InstitutionProfile, 1: ValidationErrors}
     */
    private function build(ContextInfo $context, array $input, ?int $id): array
    {
        $input = $this->filterLocales($input, $context->formLocales);
        $profile = InstitutionProfile::fromArray($context->id, $input, $id);
        if (!$this->jurisdictions->has($profile->jurisdiction)) {
            $errors = new ValidationErrors();
            $errors->add('jurisdiction', self::K . 'jurisdictionUnknown');
            return [$profile, $errors];
        }
        $errors = (new InstitutionValidator())->validate(
            $profile,
            $this->jurisdictions->get($profile->jurisdiction),
            $context->primaryLocale,
            $input,
        );
        return [$profile, $errors];
    }

    /**
     * @return array<string, mixed>
     */
    public function export(ContextInfo $context): array
    {
        return [
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'exportedAt' => gmdate('c'),
            'profile' => $this->getOrDraft($context)->toArray(portable: true),
        ];
    }

    /**
     * Imports a profile exported from another context or installation.
     * Signers and accounts of the import replace the current ones.
     *
     * @return array{0: ?InstitutionProfile, 1: ValidationErrors}
     */
    public function import(ContextInfo $context, string $json, ?int $actorUserId = null, ?string $ip = null): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::EXPORT_FORMAT || !is_array($data['profile'] ?? null)) {
            $errors = new ValidationErrors();
            $errors->add('import', self::K . 'importFormat');
            return [null, $errors];
        }
        if ((int) ($data['version'] ?? 0) > self::EXPORT_VERSION) {
            $errors = new ValidationErrors();
            $errors->add('import', self::K . 'importVersion');
            return [null, $errors];
        }
        $profile = $data['profile'];
        // Ids from another installation must never overwrite local rows.
        foreach (['signers', 'paymentAccounts'] as $list) {
            $profile[$list] = array_map(static function ($item) {
                if (is_array($item)) {
                    unset($item['id'], $item['signaturePath']);
                }
                return $item;
            }, (array) ($profile[$list] ?? []));
        }
        unset($profile['logoPath'], $profile['sealPath']);
        $current = $this->repository->findByContextId($context->id);
        $profile['logoPath'] = $current?->logoPath;
        $profile['sealPath'] = $current?->sealPath;

        return $this->save($context, $profile, $actorUserId, $ip);
    }

    /**
     * Drops values in locales the context does not use.
     *
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
        foreach (InstitutionProfile::LOCALIZED_FIELDS as $field) {
            if (isset($input[$field]) && is_array($input[$field])) {
                $input[$field] = array_intersect_key($input[$field], $keep);
            }
        }
        foreach (['signers'] as $list) {
            foreach ((array) ($input[$list] ?? []) as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['fullName', 'position'] as $field) {
                    if (isset($item[$field]) && is_array($item[$field])) {
                        $input[$list][$i][$field] = array_intersect_key($item[$field], $keep);
                    }
                }
            }
        }
        return $input;
    }
}
