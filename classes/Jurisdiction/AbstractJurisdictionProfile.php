<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Iban;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

abstract class AbstractJurisdictionProfile implements JurisdictionProfile
{
    protected const K = 'plugins.generic.conferenceManager.validation.';

    public function isLegalIdRequired(): bool
    {
        return false;
    }

    public function validatePersonalTaxId(string $taxId): ?string
    {
        return preg_match('/^[A-Za-z0-9-]{4,32}$/', $taxId) === 1 ? null : self::K . 'taxId';
    }

    public function getPersonalTaxIdLabelKey(): string
    {
        return 'plugins.generic.conferenceManager.payer.taxId';
    }

    public function validateIban(string $iban, PaymentAccountKind $kind): ?string
    {
        return Iban::isValid($iban) ? null : self::K . 'iban';
    }

    public function allowedAccountKinds(): array
    {
        return [PaymentAccountKind::Bank, PaymentAccountKind::Partner];
    }

    public function getFeatures(): array
    {
        return [];
    }

    public function getInstitutionExtraFields(): array
    {
        return [];
    }

    public function getSampleText(string $field, string $locale): ?string
    {
        $dir = $this->samplesDir();
        if ($dir === null || !in_array($field, ['privacyNotice', 'retentionNotice'], true)) {
            return null;
        }
        foreach ([strtolower(substr($locale, 0, 2)), 'en'] as $lang) {
            $file = $dir . '/' . $field . '.' . $lang . ($field === 'privacyNotice' ? '.html' : '.txt');
            if (is_file($file)) {
                return (string) file_get_contents($file);
            }
        }
        return null;
    }

    /** Directory with "{field}.{language}.html|txt"; null when the profile has no samples. */
    protected function samplesDir(): ?string
    {
        return null;
    }

    public function getPaymentAccountExtraFields(PaymentAccountKind $kind): array
    {
        return [
            new FieldDefinition('swift', 'plugins.generic.conferenceManager.account.swift', maxLength: 11),
        ];
    }

    public function validateInstitutionExtra(array $extra): ValidationErrors
    {
        $errors = new ValidationErrors();
        foreach ($this->getInstitutionExtraFields() as $field) {
            $value = trim((string) ($extra[$field->name] ?? ''));
            if ($value === '' && $field->required) {
                $errors->add($field->name, self::K . 'required');
            } elseif (mb_strlen($value) > $field->maxLength) {
                $errors->add($field->name, self::K . 'tooLong');
            }
        }
        return $errors;
    }
}
