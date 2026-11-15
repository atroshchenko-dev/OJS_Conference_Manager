<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccount;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Preset\Preset;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * The first setup wizard (INS-08): a preset (DST-07) and a few fields of
 * the first edition make an edition with its categories, tariffs, payment
 * rules and certificate settings. It can be run again for another edition.
 */
final class SetupService
{
    public const SETTING = 'setup';

    /** Fields of the edition the wizard asks for; the preset gives the rest. */
    public const EDITION_INPUT = ['name', 'path', 'timezone', 'startsAt', 'endsAt', 'cfpOpensAt', 'abstractDeadline', 'paperDeadline', 'registrationClosesAt', 'externalProceedingsUrl', 'isbn'];

    private const K = 'plugins.generic.conferenceManager.setup.';

    /**
     * @param callable(ContextInfo): InstitutionProfile $institution
     */
    public function __construct(
        private readonly PresetLibrary $presets,
        private readonly EditionService $editions,
        private readonly FeeService $fees,
        private readonly InvoiceService $invoices,
        private readonly CertificateService $certificates,
        private readonly mixed $institution,
        private readonly SettingsStore $settings,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * What the wizard shows: whether it ran, the institution type and the
     * presets that fit the jurisdiction of the context.
     *
     * @return array<string, mixed>
     */
    public function state(ContextInfo $context, int $editionCount): array
    {
        $profile = ($this->institution)($context);
        $done = $this->settings->get($context->id, self::SETTING);
        return [
            'done' => is_array($done),
            'last' => is_array($done) ? $done : null,
            'editionCount' => $editionCount,
            'institutionType' => $profile->type->value,
            'jurisdiction' => $profile->jurisdiction,
            'accounts' => array_values(array_unique(array_map(static fn (PaymentAccount $a): string => $a->kind->value, $profile->activePaymentAccounts()))),
            'presets' => array_map(
                static fn (Preset $p): array => $p->toArray() + ['suitsType' => $p->suits($profile->type, $profile->jurisdiction)],
                array_values(array_filter($this->presets->all(), static fn (Preset $p): bool => $p->suits(null, $profile->jurisdiction))),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input preset (code) and the edition fields of EDITION_INPUT
     * @param callable(string $key, string $locale): string $translate
     * @return array{0: ?Edition, 1: ValidationErrors, 2: list<array{key: string, params: array<string, string>}>}
     */
    public function apply(ContextInfo $context, array $input, callable $translate, ?int $actorId, DateTimeImmutable $now): array
    {
        $errors = new ValidationErrors();
        $profile = ($this->institution)($context);
        $preset = $this->presets->find((string) ($input['preset'] ?? ''));
        if ($preset === null || !$preset->suits(null, $profile->jurisdiction)) {
            $errors->add('preset', self::K . 'error.preset');
            return [null, $errors, []];
        }

        $editionInput = array_intersect_key($input, array_flip(self::EDITION_INPUT)) + $preset->edition + ['status' => 'draft'];
        [$edition, $errors] = $this->editions->create($context, $editionInput, $actorId);
        if ($edition === null || $edition->id === null) {
            return [null, $errors, []];
        }
        $editionId = $edition->id;
        $warnings = [];

        $categoryIds = [];
        foreach ($preset->categories as $order => $c) {
            $names = [];
            foreach ($context->formLocales as $locale) {
                $name = $c['name'][$locale] ?? ($c['nameKey'] !== null ? $translate($c['nameKey'], $locale) : null);
                if ($name !== null && $name !== '') {
                    $names[$locale] = $name;
                }
            }
            if (!isset($names[$context->primaryLocale])) {
                $names[$context->primaryLocale] = $c['name'] !== [] ? (string) reset($c['name']) : $c['code'];
            }
            $saved = $this->fees->saveCategory($context, $editionId, null, ['code' => $c['code'], 'name' => $names, 'requiresProof' => $c['requiresProof'], 'sortOrder' => $order], $actorId);
            if ($saved !== null && $saved[0] !== null && $saved[0]->id !== null) {
                $categoryIds[$c['code']] = $saved[0]->id;
            }
        }

        foreach ($preset->tariffs as $t) {
            if (!isset($categoryIds[$t['category']])) {
                continue;
            }
            $this->fees->saveTariff($context, $editionId, null, ['categoryId' => $categoryIds[$t['category']]] + $t, $actorId);
        }

        $accounts = $profile->activePaymentAccounts();
        foreach ($preset->paymentRules as $r) {
            $mode = PaymentMode::from($r['mode']);
            $account = $this->account($mode, $accounts);
            if ($mode->needsAccount() && $account === null) {
                $warnings[] = ['key' => self::K . 'warning.account', 'params' => ['mode' => $translate($mode->labelKey(), $context->primaryLocale)]];
                continue;
            }
            if ($mode->needsUrl()) {
                $warnings[] = ['key' => self::K . 'warning.url', 'params' => []];
                continue;
            }
            $categoryId = $r['category'] === null ? null : ($categoryIds[$r['category']] ?? null);
            if ($r['category'] !== null && $categoryId === null) {
                continue;
            }
            $this->invoices->saveRule($context, $editionId, null, ['categoryId' => $categoryId, 'mode' => $mode->value, 'paymentAccountId' => $account?->id], $actorId);
        }

        if ($preset->vector) {
            if ($this->certificates->professionalDevelopmentAllowed($context)) {
                $this->certificates->setVectorEnabled($context, true, $actorId);
            } else {
                $warnings[] = ['key' => self::K . 'warning.vector', 'params' => []];
            }
        }

        $record = ['preset' => $preset->code, 'editionId' => $editionId, 'at' => $now->format(DATE_ATOM)];
        $this->settings->set($context->id, self::SETTING, $record);
        $this->audit?->log($context->id, $actorId, 'setup', $editionId, 'apply', null, $record);
        return [$edition, $errors, $warnings];
    }

    /** @param list<PaymentAccount> $accounts */
    private function account(PaymentMode $mode, array $accounts): ?PaymentAccount
    {
        $order = match ($mode) {
            PaymentMode::TreasuryTransfer => [PaymentAccountKind::Treasury],
            PaymentMode::BankTransfer => [PaymentAccountKind::Bank, PaymentAccountKind::Partner],
            PaymentMode::ExternalOrganizer => [PaymentAccountKind::Partner],
            default => [],
        };
        foreach ($order as $kind) {
            foreach ($accounts as $a) {
                if ($a->kind === $kind) {
                    return $a;
                }
            }
        }
        return null;
    }
}
