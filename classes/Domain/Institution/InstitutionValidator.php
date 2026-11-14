<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\TextTemplate;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

final class InstitutionValidator
{
    private const K = 'plugins.generic.conferenceManager.validation.';

    /**
     * @param string $primaryLocale the context's primary locale: name is required in it
     * @param array<string, mixed> $raw the raw input, to report unknown enum values
     */
    public function validate(
        InstitutionProfile $profile,
        JurisdictionRules $rules,
        string $primaryLocale,
        array $raw = [],
    ): ValidationErrors {
        $errors = new ValidationErrors();

        if ($profile->jurisdiction !== $rules->getCode()) {
            $errors->add('jurisdiction', self::K . 'jurisdictionUnknown');
        }
        if (isset($raw['institutionType']) && InstitutionType::tryFrom((string) $raw['institutionType']) === null) {
            $errors->add('institutionType', self::K . 'invalidChoice');
        }
        if (isset($raw['ownership']) && Ownership::tryFrom((string) $raw['ownership']) === null) {
            $errors->add('ownership', self::K . 'invalidChoice');
        }

        if ($profile->text('name')->get($primaryLocale) === null) {
            $errors->add('name', self::K . 'requiredInPrimaryLocale');
        }
        foreach (InstitutionProfile::LOCALIZED_FIELDS as $field) {
            foreach ($profile->text($field)->toArray() as $text) {
                $limit = in_array($field, ['privacyNotice', 'retentionNotice'], true) ? 20000 : 500;
                if (mb_strlen($text) > $limit) {
                    $errors->add($field, self::K . 'tooLong');
                    break;
                }
            }
        }

        if ($profile->legalId === null) {
            if ($rules->isLegalIdRequired()) {
                $errors->add('legalId', self::K . 'required');
            }
        } elseif (($key = $rules->validateLegalId($profile->legalId)) !== null) {
            $errors->add('legalId', $key);
        }

        if ($profile->contactEmail !== '' && filter_var($profile->contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors->add('contactEmail', self::K . 'email');
        }
        if ($profile->website !== '' && !preg_match('#^https?://[^\s]+$#i', $profile->website)) {
            $errors->add('website', self::K . 'url');
        }

        $errors->merge($rules->validateInstitutionExtra($profile->extra), 'extra.');

        foreach ($profile->signers as $i => $signer) {
            if ($signer->fullName->get($primaryLocale) === null) {
                $errors->add("signers.$i.fullName", self::K . 'requiredInPrimaryLocale');
            }
            if ($signer->position->get($primaryLocale) === null) {
                $errors->add("signers.$i.position", self::K . 'requiredInPrimaryLocale');
            }
        }

        $allowedKinds = $rules->allowedAccountKinds();
        foreach ($profile->paymentAccounts as $i => $account) {
            if (!in_array($account->kind, $allowedKinds, true)) {
                $errors->add("paymentAccounts.$i.kind", self::K . 'accountKindNotAllowed');
            }
            if ($account->recipientName === '') {
                $errors->add("paymentAccounts.$i.recipientName", self::K . 'required');
            }
            if ($account->iban === '') {
                $errors->add("paymentAccounts.$i.iban", self::K . 'required');
            } elseif (($key = $rules->validateIban($account->iban, $account->kind)) !== null) {
                $errors->add("paymentAccounts.$i.iban", $key);
            }
            if ($account->legalId !== '' && ($key = $rules->validateLegalId($account->legalId)) !== null) {
                $errors->add("paymentAccounts.$i.legalId", $key);
            }
            $unknown = (new TextTemplate($account->purposeTemplate))->unknownVariables(PaymentAccount::PURPOSE_VARIABLES);
            if ($unknown !== []) {
                $errors->add("paymentAccounts.$i.purposeTemplate", self::K . 'unknownTemplateVariable');
            }
        }

        return $errors;
    }
}
