<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\JurisdictionRules;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * Who pays an invoice (JUR-05): the person themselves, or an organisation
 * with its legal id (ЄДРПОУ in UA). The personal tax number (РНОКПП in UA)
 * is asked only when the journal turned it on, because accounting rarely
 * needs it and it is sensitive personal data.
 */
final class PayerDetails
{
    public const PERSON = 'person';
    public const ORGANIZATION = 'organization';
    private const K = 'plugins.generic.conferenceManager.payer.error.';

    public function __construct(
        public readonly string $kind = self::PERSON,
        public readonly string $organization = '',
        public readonly string $legalId = '',
        public readonly string $taxId = '',
    ) {
    }

    /**
     * @param array<string, mixed> $input kind, organization, legalId, taxId
     * @return array{0: ?self, 1: ValidationErrors}
     */
    public static function fromInput(array $input, JurisdictionRules $rules, bool $taxIdEnabled): array
    {
        $errors = new ValidationErrors();
        $kind = (string) ($input['kind'] ?? self::PERSON);
        $text = static fn (string $k): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$k] ?? '')));
        if (!in_array($kind, [self::PERSON, self::ORGANIZATION], true)) {
            $errors->add('kind', self::K . 'kind');
            return [null, $errors];
        }
        if ($kind === self::ORGANIZATION) {
            $name = $text('organization');
            $legalId = str_replace(' ', '', $text('legalId'));
            if ($name === '' || mb_strlen($name) > 255) {
                $errors->add('organization', self::K . 'organization');
            }
            if ($legalId === '') {
                $errors->add('legalId', self::K . 'legalId');
            } elseif (($e = $rules->validateLegalId($legalId)) !== null) {
                $errors->add('legalId', $e);
            }
            return $errors->isEmpty() ? [new self(self::ORGANIZATION, $name, $legalId), $errors] : [null, $errors];
        }
        $taxId = $taxIdEnabled ? str_replace(' ', '', $text('taxId')) : '';
        if ($taxIdEnabled) {
            if ($taxId === '') {
                $errors->add('taxId', self::K . 'taxId');
            } elseif (($e = $rules->validatePersonalTaxId($taxId)) !== null) {
                $errors->add('taxId', $e);
            }
        }
        return $errors->isEmpty() ? [new self(self::PERSON, '', '', $taxId), $errors] : [null, $errors];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $kind = ($data['kind'] ?? '') === self::ORGANIZATION ? self::ORGANIZATION : self::PERSON;
        return new self($kind, (string) ($data['organization'] ?? ''), (string) ($data['legalId'] ?? ''), (string) ($data['taxId'] ?? ''));
    }

    /** @return array{kind: string, organization: string, legalId: string, taxId: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'organization' => $this->organization, 'legalId' => $this->legalId, 'taxId' => $this->taxId];
    }

    public function isOrganization(): bool
    {
        return $this->kind === self::ORGANIZATION;
    }
}
