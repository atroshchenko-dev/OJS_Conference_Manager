<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

/**
 * A letter to a participant (COM-01). The journal edits its template in
 * Settings → Workflow → Emails; the plugin fills the variables.
 */
abstract class ConferenceParticipantMailable extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    /** @phpstan-ignore property.defaultValue (OJS declares the groups as int but uses strings) */
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    /** @param array<string, string> $data escaped values of the variables */
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct([$context]);
        $this->addData($data);
    }

    public static function getDataDescriptions(): array
    {
        $k = 'plugins.generic.conferenceManager.mailable.variable.';
        $out = parent::getDataDescriptions();
        foreach ([...ParticipantMailTexts::COMMON, ...(ParticipantMailTexts::VARIABLES[static::getEmailTemplateKey()] ?? [])] as $name) {
            $out[$name] = __($k . $name);
        }
        return $out;
    }

    /** @return list<class-string<self>> */
    public static function classes(): array
    {
        return [
            ParticipationConfirm::class, InvoiceIssuedNotify::class, ProofAcceptedNotify::class, ProofRejectedNotify::class,
            PaymentReminder::class, PaperReminder::class, EventReminder::class,
        ];
    }

    /** @return class-string<self>|null */
    public static function forKey(string $key): ?string
    {
        foreach (self::classes() as $class) {
            if ($class::getEmailTemplateKey() === $key) {
                return $class;
            }
        }
        return null;
    }
}
