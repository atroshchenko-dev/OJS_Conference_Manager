<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

/** Decision Poster: the letter to the authors. */
final class AcceptPosterNotifyAuthor extends ConferenceDecisionMailable
{
    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.accept.poster.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.accept.description';
    protected static ?string $emailTemplateKey = 'CONF_DECISION_POSTER';
}
