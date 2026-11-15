<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

/** Decision Online: the letter to the authors. */
final class AcceptOnlineNotifyAuthor extends ConferenceDecisionMailable
{
    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.accept.online.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.accept.description';
    protected static ?string $emailTemplateKey = 'CONF_DECISION_ONLINE';
}
