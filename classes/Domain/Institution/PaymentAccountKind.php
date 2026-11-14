<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

enum PaymentAccountKind: string
{
    case Bank = 'bank';
    case Treasury = 'treasury';
    case Partner = 'partner';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.paymentAccount.kind.' . $this->value;
    }
}
