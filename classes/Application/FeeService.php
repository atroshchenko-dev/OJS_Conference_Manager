<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\CategoryPresets;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeValidator;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PricingMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Quote;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Tariff;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffCalculator;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffQuery;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * Participant categories and the tariff matrix of an edition (FEE-01…03).
 * Every method first checks that the edition belongs to the context;
 * null means "no such edition".
 */
final class FeeService
{
    private const K = 'plugins.generic.conferenceManager.fee.error.';
    private const V = 'plugins.generic.conferenceManager.validation.';

    public function __construct(
        private readonly FeeRepository $fees,
        private readonly EditionRepository $editions,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @return list<FeeCategory>|null */
    public function categories(ContextInfo $context, int $editionId): ?array
    {
        return $this->edition($context, $editionId) === null ? null : $this->fees->categories($editionId);
    }

    /** @return list<Tariff>|null */
    public function tariffs(ContextInfo $context, int $editionId): ?array
    {
        return $this->edition($context, $editionId) === null ? null : $this->fees->tariffs($editionId);
    }

    /**
     * @param array<string, mixed> $input code, name{locale}, requiresProof, sortOrder
     * @return array{0: ?FeeCategory, 1: ValidationErrors}|null
     */
    public function saveCategory(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId = null): ?array
    {
        if ($this->edition($context, $editionId) === null) {
            return null;
        }
        $current = $id === null ? null : $this->fees->findCategory($editionId, $id);
        if ($id !== null && $current === null) {
            return null;
        }
        $others = $this->fees->categories($editionId);
        $category = new FeeCategory(
            $id,
            $editionId,
            strtolower(trim((string) ($input['code'] ?? $current->code ?? ''))),
            array_key_exists('name', $input) ? new LocalizedText((array) $input['name']) : ($current->name ?? new LocalizedText()),
            array_key_exists('requiresProof', $input) ? filter_var($input['requiresProof'], FILTER_VALIDATE_BOOLEAN) : ($current->requiresProof ?? false),
            array_key_exists('sortOrder', $input) ? (int) $input['sortOrder'] : ($current->sortOrder ?? count($others)),
        );
        $errors = (new FeeValidator())->validateCategory($category, $context->primaryLocale, $others);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $saved = $this->fees->saveCategory($category);
        $this->audit?->log($context->id, $actorId, 'fee_category', $saved->id, $id === null ? 'create' : 'update', $current?->toArray(), $saved->toArray());
        return [$saved, $errors];
    }

    /**
     * Refused while participants have the category (they would silently lose their fee).
     */
    public function deleteCategory(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): ?ValidationErrors
    {
        $category = $this->edition($context, $editionId) === null ? null : $this->fees->findCategory($editionId, $id);
        if ($category === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if ($this->fees->categoryInUse($id)) {
            $errors->add('id', self::K . 'categoryInUse');
            return $errors;
        }
        $this->fees->deleteCategory($editionId, $id);
        $this->audit?->log($context->id, $actorId, 'fee_category', $id, 'delete', $category->toArray());
        return $errors;
    }

    /**
     * Adds the preset categories of the institution type that the edition
     * does not have yet (by code). Names in every form language.
     *
     * @param callable(string $key, string $locale): string $translate
     * @return list<FeeCategory>|null the categories of the edition after the change
     */
    public function applyPreset(ContextInfo $context, int $editionId, InstitutionType $type, callable $translate, ?int $actorId = null): ?array
    {
        if ($this->edition($context, $editionId) === null) {
            return null;
        }
        $existing = array_map(static fn (FeeCategory $c): string => $c->code, $this->fees->categories($editionId));
        $order = count($existing);
        foreach (CategoryPresets::for($type) as $preset) {
            if (in_array($preset['code'], $existing, true)) {
                continue;
            }
            $names = [];
            foreach ($context->formLocales as $locale) {
                $names[$locale] = $translate($preset['nameKey'], $locale);
            }
            $saved = $this->fees->saveCategory(new FeeCategory(null, $editionId, $preset['code'], new LocalizedText($names), $preset['requiresProof'], $order++));
            $this->audit?->log($context->id, $actorId, 'fee_category', $saved->id, 'create_preset', null, $saved->toArray());
        }
        return $this->fees->categories($editionId);
    }

    /**
     * @param array<string, mixed> $input categoryId, material, pricing, amount ("150.00"), currency,
     *   presentationType, minPages, maxPages, validFrom, validTo (local time of the edition)
     * @return array{0: ?Tariff, 1: ValidationErrors}|null
     */
    public function saveTariff(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId = null): ?array
    {
        $edition = $this->edition($context, $editionId);
        if ($edition === null || ($id !== null && $this->fees->findTariff($editionId, $id) === null)) {
            return null;
        }
        $errors = new ValidationErrors();
        $categoryId = (int) ($input['categoryId'] ?? 0);
        if ($this->fees->findCategory($editionId, $categoryId) === null) {
            $errors->add('categoryId', self::V . 'required');
        }
        $material = MaterialType::tryFrom((string) ($input['material'] ?? ''));
        if ($material === null) {
            $errors->add('material', self::V . 'required');
        }
        $pricing = PricingMode::tryFrom((string) ($input['pricing'] ?? PricingMode::Flat->value));
        if ($pricing === null) {
            $errors->add('pricing', self::V . 'required');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $amount = null;
        if (!Money::isCurrency($currency)) {
            $errors->add('currency', self::K . 'currency');
        } else {
            $raw = $input['amount'] ?? '';
            $amount = Money::parse(is_int($raw) ? $raw : (string) $raw, $currency);
            if ($amount === null) {
                $errors->add('amount', self::K . 'amount');
            }
        }
        $presentation = null;
        if (($input['presentationType'] ?? '') !== '' && ($input['presentationType'] ?? null) !== null) {
            $presentation = PresentationType::tryFrom((string) $input['presentationType']);
            if ($presentation === null) {
                $errors->add('presentationType', self::V . 'required');
            }
        }
        $pages = [];
        foreach (['minPages', 'maxPages'] as $field) {
            $v = $input[$field] ?? null;
            if ($v === null || $v === '') {
                $pages[$field] = null;
            } elseif (is_numeric($v) && (string) (int) $v === trim((string) $v)) {
                $pages[$field] = (int) $v;
            } else {
                $pages[$field] = null;
                $errors->add('maxPages', self::K . 'pages');
            }
        }
        $dates = [];
        foreach (['validFrom', 'validTo'] as $field) {
            $parsed = DateInput::parse($input[$field] ?? null, $edition->timezone);
            if ($parsed === false) {
                $errors->add($field, self::V . 'date');
                $parsed = null;
            }
            $dates[$field] = $parsed;
        }
        if (!$errors->isEmpty() || $material === null || $pricing === null || $amount === null) {
            return [null, $errors];
        }
        $tariff = new Tariff($id, $categoryId, $material, $pricing, $amount, $presentation, $pages['minPages'], $pages['maxPages'], $dates['validFrom'], $dates['validTo']);
        $errors = (new FeeValidator())->validateTariff($tariff, $this->fees->tariffs($editionId));
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $saved = $this->fees->saveTariff($tariff);
        $this->audit?->log($context->id, $actorId, 'tariff', $saved->id, $id === null ? 'create' : 'update', null, $this->present($saved, $edition));
        return [$saved, $errors];
    }

    public function deleteTariff(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): bool
    {
        if ($this->edition($context, $editionId) === null || !$this->fees->deleteTariff($editionId, $id)) {
            return false;
        }
        $this->audit?->log($context->id, $actorId, 'tariff', $id, 'delete');
        return true;
    }

    public function quote(ContextInfo $context, int $editionId, TariffQuery $query): ?Quote
    {
        return $this->edition($context, $editionId) === null
            ? null
            : (new TariffCalculator())->quote($this->fees->tariffs($editionId), $query);
    }

    /** @return array<string, mixed> */
    public function present(Tariff $t, Edition $edition): array
    {
        return [
            'id' => $t->id,
            'categoryId' => $t->categoryId,
            'material' => $t->material->value,
            'pricing' => $t->pricing->value,
            'amount' => $t->amount->decimal(),
            'amountMinor' => $t->amount->minor,
            'currency' => $t->amount->currency,
            'presentationType' => $t->presentationType?->value,
            'minPages' => $t->minPages,
            'maxPages' => $t->maxPages,
            'validFrom' => DateInput::toIsoUtc($t->validFrom),
            'validTo' => DateInput::toIsoUtc($t->validTo),
            'local' => [
                'validFrom' => DateInput::toLocal($t->validFrom, $edition->timezone),
                'validTo' => DateInput::toLocal($t->validTo, $edition->timezone),
            ],
        ];
    }

    public function edition(ContextInfo $context, int $editionId): ?Edition
    {
        return $this->editions->find($context->id, $editionId);
    }
}
