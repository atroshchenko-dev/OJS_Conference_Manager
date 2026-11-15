<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;

final class PaymentReminder extends ConferenceParticipantMailable
{
    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.remindPayment.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.remindPayment.description';
    protected static ?string $emailTemplateKey = ParticipantMailTexts::REMIND_PAYMENT;
}
