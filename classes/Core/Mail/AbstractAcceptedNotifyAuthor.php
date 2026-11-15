<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\decision\Decision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AbstractAccepted;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

/** DEC-07: the letter of the decision "abstract accepted, send the full text". */
final class AbstractAcceptedNotifyAuthor extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.abstractAccepted.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.abstractAccepted.description';
    protected static ?string $emailTemplateKey = AbstractAccepted::EMAIL_KEY;
    protected static bool $supportsTemplates = true;
    /** @phpstan-ignore property.defaultValue (OJS declares the groups as int but uses strings) */
    protected static array $groupIds = [self::GROUP_REVIEW];
    protected static array $fromRoleIds = [Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Context $context, Submission $submission, Decision $decision)
    {
        parent::__construct(func_get_args());
        $this->addData(ConferenceMailData::of($context, $submission) + [ConferenceMailData::PAPER_DEADLINE => ConferenceMailData::paperDeadline($context, $submission)]);
    }

    public static function getDataDescriptions(): array
    {
        return parent::getDataDescriptions() + ConferenceMailData::descriptions() + [
            ConferenceMailData::PAPER_DEADLINE => __('plugins.generic.conferenceManager.mailable.variable.paperDeadline'),
        ];
    }
}
