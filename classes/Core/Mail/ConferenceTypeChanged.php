<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionMailTexts;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

/**
 * The letter to the authors when the editor changes the presentation type
 * after the decision (DEC-05).
 */
final class ConferenceTypeChanged extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    public const OLD_TYPE = 'oldPresentationType';

    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.typeChanged.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.typeChanged.description';
    protected static ?string $emailTemplateKey = DecisionMailTexts::TYPE_CHANGED;
    /** @phpstan-ignore property.defaultValue (OJS declares the groups as int but uses strings) */
    protected static array $groupIds = [self::GROUP_SUBMISSION];
    protected static array $fromRoleIds = [Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Context $context, Submission $submission, string $oldType = '')
    {
        parent::__construct([$context, $submission]);
        $this->addData(ConferenceMailData::of($context, $submission) + [self::OLD_TYPE => $oldType]);
    }

    public static function getDataDescriptions(): array
    {
        return parent::getDataDescriptions() + ConferenceMailData::descriptions() + [
            self::OLD_TYPE => __('plugins.generic.conferenceManager.mailable.variable.oldPresentationType'),
        ];
    }
}
