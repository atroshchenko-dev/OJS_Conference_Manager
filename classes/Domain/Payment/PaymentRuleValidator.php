<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccount;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

final class PaymentRuleValidator
{
    private const K = 'plugins.generic.conferenceManager.payment.error.';

    /**
     * @param list<PaymentRule> $others rules of the edition
     * @param list<PaymentAccount> $accounts active accounts of the institution
     */
    public function validate(PaymentRule $rule, array $others, array $accounts): ValidationErrors
    {
        $errors = new ValidationErrors();
        foreach ($others as $other) {
            if ($other->id !== $rule->id && $other->categoryId === $rule->categoryId) {
                $errors->add('categoryId', self::K . 'duplicate');
            }
        }
        if ($rule->mode->needsAccount()) {
            $account = null;
            foreach ($accounts as $a) {
                if ($a->id === $rule->paymentAccountId) {
                    $account = $a;
                }
            }
            if ($account === null) {
                $errors->add('paymentAccountId', self::K . 'account');
            } elseif ($rule->mode === PaymentMode::TreasuryTransfer && $account->kind !== PaymentAccountKind::Treasury) {
                $errors->add('paymentAccountId', self::K . 'treasuryAccount');
            } elseif ($rule->mode === PaymentMode::BankTransfer && $account->kind === PaymentAccountKind::Treasury) {
                $errors->add('paymentAccountId', self::K . 'bankAccount');
            }
        }
        $url = $rule->externalUrl;
        if ($rule->mode->needsUrl() && ($url === null || $url === '')) {
            $errors->add('externalUrl', self::K . 'url');
        } elseif ($url !== null && $url !== '' && (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            $errors->add('externalUrl', self::K . 'url');
        }
        return $errors;
    }
}
