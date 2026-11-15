<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Preset;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\CategoryPresets;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PricingMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;

/**
 * A starting configuration for an institution type (DST-07): the event
 * type and modes of a first edition, participant categories, tariffs,
 * payment rules and certificate settings. Presets are JSON files in the
 * package (`presets/`) or added by a site in `{files_dir}/conferenceManager/presets/`.
 * Nothing in a preset is required later: everything it creates is edited
 * like anything made by hand.
 */
final class Preset
{
    public const SCHEMA = 1;

    /** Edition fields a preset may set; the rest come from the wizard. */
    public const EDITION_FIELDS = ['eventType', 'submissionMode', 'publicationMode', 'format', 'reviewMode', 'hours', 'ectsX100'];

    /**
     * @param list<InstitutionType> $institutionTypes empty means any type
     * @param array<string, string|int> $edition
     * @param list<array{code: string, requiresProof: bool, name: array<string, string>, nameKey: ?string}> $categories
     * @param list<array{category: string, material: string, pricing: string, amount: string, currency: string}> $tariffs
     * @param list<array{category: ?string, mode: string}> $paymentRules
     */
    public function __construct(
        public readonly string $code,
        public readonly LocalizedText $name,
        public readonly LocalizedText $description,
        public readonly ?string $jurisdiction,
        public readonly array $institutionTypes,
        public readonly array $edition,
        public readonly array $categories,
        public readonly array $tariffs,
        public readonly array $paymentRules,
        public readonly bool $professionalDevelopment,
        public readonly bool $vector,
        public readonly string $source,
    ) {
    }

    /**
     * Reads a decoded JSON file. Errors are plain English messages for the
     * site administrator (the file is technical, its author reads them).
     *
     * @param mixed $data
     * @return array{0: ?self, 1: list<string>}
     */
    public static function parse(mixed $data, string $source = 'package'): array
    {
        $errors = [];
        if (!is_array($data)) {
            return [null, ['the file is not a JSON object']];
        }
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'schema must be ' . self::SCHEMA;
        }
        $code = (string) ($data['code'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $code)) {
            $errors[] = 'code must be 2–63 lowercase latin letters, digits or dashes';
        }
        $name = self::text($data['name'] ?? null);
        if ($name->isEmpty()) {
            $errors[] = 'name needs at least one language';
        }
        $jurisdiction = $data['jurisdiction'] ?? null;
        if ($jurisdiction !== null && (!is_string($jurisdiction) || !preg_match('/^[A-Za-z0-9_-]{2,32}$/', $jurisdiction))) {
            $errors[] = 'jurisdiction must be a profile code or null';
            $jurisdiction = null;
        }

        $types = [];
        foreach ((array) ($data['institutionTypes'] ?? []) as $t) {
            $type = InstitutionType::tryFrom((string) $t);
            $type === null ? $errors[] = "unknown institution type \"$t\"" : $types[] = $type;
        }

        $edition = [];
        $raw = (array) ($data['edition'] ?? []);
        foreach ($raw as $field => $value) {
            if (!in_array($field, self::EDITION_FIELDS, true)) {
                $errors[] = "edition.$field is not a preset field";
                continue;
            }
            $ok = match ($field) {
                'eventType' => EventType::tryFrom((string) $value) !== null,
                'submissionMode' => SubmissionMode::tryFrom((string) $value) !== null,
                'publicationMode' => PublicationMode::tryFrom((string) $value) !== null,
                'format' => EventFormat::tryFrom((string) $value) !== null,
                'reviewMode' => ReviewMode::tryFrom((string) $value) !== null,
                default => is_int($value) && $value >= 0 && $value <= 100000,
            };
            if (!$ok) {
                $errors[] = "edition.$field has an unknown value";
                continue;
            }
            $edition[$field] = is_int($value) ? $value : (string) $value;
        }

        $known = CategoryPresets::allCodes();
        $categories = [];
        foreach ((array) ($data['categories'] ?? []) as $i => $c) {
            $c = (array) $c;
            $cCode = strtolower(trim((string) ($c['code'] ?? '')));
            if (!preg_match('/^[a-z0-9_]{1,32}$/', $cCode)) {
                $errors[] = "categories[$i].code must be latin letters, digits or _";
                continue;
            }
            if (isset($categories[$cCode])) {
                $errors[] = "categories[$i].code \"$cCode\" repeats";
                continue;
            }
            $cName = self::text($c['name'] ?? null)->toArray();
            $builtIn = in_array($cCode, $known, true);
            if ($cName === [] && !$builtIn) {
                $errors[] = "categories[$i].name is needed for the own code \"$cCode\"";
                continue;
            }
            $categories[$cCode] = [
                'code' => $cCode,
                'requiresProof' => (bool) ($c['requiresProof'] ?? false),
                'name' => $cName,
                'nameKey' => $builtIn ? 'plugins.generic.conferenceManager.fee.preset.' . $cCode : null,
            ];
        }

        $tariffs = [];
        foreach ((array) ($data['tariffs'] ?? []) as $i => $t) {
            $t = (array) $t;
            $cat = (string) ($t['category'] ?? '');
            $amount = (string) ($t['amount'] ?? '');
            $currency = strtoupper((string) ($t['currency'] ?? ''));
            $problem = match (true) {
                !isset($categories[$cat]) => 'category is not in categories',
                MaterialType::tryFrom((string) ($t['material'] ?? '')) === null => 'material is unknown',
                PricingMode::tryFrom((string) ($t['pricing'] ?? 'flat')) === null => 'pricing is unknown',
                !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) => 'amount must look like "400.00"',
                !Money::isCurrency($currency) => 'currency must be a three-letter code',
                default => null,
            };
            if ($problem !== null) {
                $errors[] = "tariffs[$i]: $problem";
                continue;
            }
            $tariffs[] = ['category' => $cat, 'material' => (string) $t['material'], 'pricing' => (string) ($t['pricing'] ?? 'flat'), 'amount' => $amount, 'currency' => $currency];
        }

        $rules = [];
        $seen = [];
        foreach ((array) ($data['paymentRules'] ?? []) as $i => $r) {
            $r = (array) $r;
            $cat = isset($r['category']) && $r['category'] !== '' ? (string) $r['category'] : null;
            if ($cat !== null && !isset($categories[$cat])) {
                $errors[] = "paymentRules[$i]: category is not in categories";
                continue;
            }
            if (PaymentMode::tryFrom((string) ($r['mode'] ?? '')) === null) {
                $errors[] = "paymentRules[$i]: mode is unknown";
                continue;
            }
            if (isset($seen[$cat ?? ''])) {
                $errors[] = "paymentRules[$i]: a second rule for the same category";
                continue;
            }
            $seen[$cat ?? ''] = true;
            $rules[] = ['category' => $cat, 'mode' => (string) $r['mode']];
        }

        $cert = (array) ($data['certificates'] ?? []);
        if ($errors !== []) {
            return [null, $errors];
        }
        return [new self(
            $code,
            $name,
            self::text($data['description'] ?? null),
            $jurisdiction,
            $types,
            $edition,
            array_values($categories),
            $tariffs,
            $rules,
            (bool) ($cert['professionalDevelopment'] ?? false),
            (bool) ($cert['vector'] ?? false),
            $source,
        ), []];
    }

    /** The preset fits the institution type and jurisdiction of a context. */
    public function suits(?InstitutionType $type, string $jurisdiction): bool
    {
        if ($this->jurisdiction !== null && strcasecmp($this->jurisdiction, $jurisdiction) !== 0) {
            return false;
        }
        return $type === null || $this->institutionTypes === [] || in_array($type, $this->institutionTypes, true);
    }

    /** @return array<string, mixed> for the wizard */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name->toArray(),
            'description' => $this->description->toArray(),
            'jurisdiction' => $this->jurisdiction,
            'institutionTypes' => array_map(static fn (InstitutionType $t): string => $t->value, $this->institutionTypes),
            'edition' => $this->edition,
            'categories' => $this->categories,
            'tariffs' => $this->tariffs,
            'paymentRules' => $this->paymentRules,
            'professionalDevelopment' => $this->professionalDevelopment,
            'vector' => $this->vector,
            'source' => $this->source,
        ];
    }

    private static function text(mixed $value): LocalizedText
    {
        if (!is_array($value)) {
            return new LocalizedText();
        }
        $out = [];
        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $out[$locale] = mb_substr(trim($text), 0, 2000);
            }
        }
        return new LocalizedText($out);
    }
}
