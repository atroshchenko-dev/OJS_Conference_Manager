<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\decision\Decision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

/**
 * The letter to the authors after the decisions 901–904 (DEC-02). One
 * subclass per decision, because OJS keys a letter template by the class.
 */
abstract class ConferenceDecisionMailable extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static bool $supportsTemplates = true;
    /** @phpstan-ignore property.defaultValue (OJS declares the groups as int but uses strings) */
    protected static array $groupIds = [self::GROUP_SUBMISSION];
    protected static array $fromRoleIds = [Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Context $context, Submission $submission, Decision $decision)
    {
        parent::__construct(func_get_args());
        // The letter names the type of this decision, not the one the author asked for.
        $type = AcceptanceDecision::tryFrom((int) $decision->getData('decision'))?->presentationType();
        $this->addData(ConferenceMailData::of($context, $submission, $type));
    }

    public static function getDataDescriptions(): array
    {
        return parent::getDataDescriptions() + ConferenceMailData::descriptions();
    }

    /** @return list<class-string<self>> */
    public static function classes(): array
    {
        return [AcceptOralNotifyAuthor::class, AcceptPosterNotifyAuthor::class, AcceptOnlineNotifyAuthor::class, AcceptAbsenteeNotifyAuthor::class];
    }

    public static function for(AcceptanceDecision $decision, Context $context, Submission $submission, Decision $d): self
    {
        return match ($decision) {
            AcceptanceDecision::Oral => new AcceptOralNotifyAuthor($context, $submission, $d),
            AcceptanceDecision::Poster => new AcceptPosterNotifyAuthor($context, $submission, $d),
            AcceptanceDecision::Online => new AcceptOnlineNotifyAuthor($context, $submission, $d),
            AcceptanceDecision::Absentee => new AcceptAbsenteeNotifyAuthor($context, $submission, $d),
        };
    }
}
