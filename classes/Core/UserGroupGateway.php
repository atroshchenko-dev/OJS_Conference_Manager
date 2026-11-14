<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\facades\Repo;
use PKP\plugins\Plugin;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

/**
 * REG-01: the "Participant" group (Reader role, self-registration) and the
 * "Registration manager" group of every context where the plugin is on.
 * Their ids are kept in the plugin settings of the context, so enabling
 * the plugin again, or calling ensure() many times, creates nothing new;
 * a group deleted by the manager is created again.
 */
final class UserGroupGateway
{
    public const PARTICIPANT = 'participantUserGroupId';
    public const REGISTRATION_MANAGER = 'registrationManagerUserGroupId';

    private const GROUPS = [
        self::PARTICIPANT => [
            'roleId' => Role::ROLE_ID_READER,
            'permitSelfRegistration' => true,
            'name' => 'plugins.generic.conferenceManager.userGroup.participant',
            'abbrev' => 'plugins.generic.conferenceManager.userGroup.participant.abbrev',
        ],
        self::REGISTRATION_MANAGER => [
            // Assistant: an editorial role without workflow stages; access
            // to registrations and payments is granted by the plugin itself.
            'roleId' => Role::ROLE_ID_ASSISTANT,
            'permitSelfRegistration' => false,
            'name' => 'plugins.generic.conferenceManager.userGroup.registrationManager',
            'abbrev' => 'plugins.generic.conferenceManager.userGroup.registrationManager.abbrev',
        ],
    ];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @return array<string, int> setting name => user group id
     */
    public function ensure(int $contextId): array
    {
        $ids = [];
        foreach (self::GROUPS as $setting => $definition) {
            $id = (int) $this->plugin->getSetting($contextId, $setting);
            if ($id <= 0 || !Repo::userGroup()->exists($id, $contextId)) {
                $id = $this->create($contextId, $definition);
                $this->plugin->updateSetting($contextId, $setting, $id, 'int');
            }
            $ids[$setting] = $id;
        }
        return $ids;
    }

    /**
     * @param array{roleId: int, permitSelfRegistration: bool, name: string, abbrev: string} $definition
     */
    private function create(int $contextId, array $definition): int
    {
        $context = Application::getContextDAO()->getById($contextId);
        $locales = $context === null ? [] : (array) ($context->getData('supportedLocales') ?? [$context->getPrimaryLocale()]);
        $name = [];
        $abbrev = [];
        foreach ($locales as $locale) {
            $name[$locale] = __($definition['name'], [], $locale);
            $abbrev[$locale] = __($definition['abbrev'], [], $locale);
        }

        $group = new UserGroup([
            'roleId' => $definition['roleId'],
            'contextId' => $contextId,
            'permitSelfRegistration' => $definition['permitSelfRegistration'],
            'permitMetadataEdit' => false,
            'permitSettings' => false,
            'isDefault' => false,
            'showTitle' => true,
            'masthead' => false,
        ]);
        $group->save();
        // Locale keys let OJS fill the name in when a locale is added later.
        $group->fill([
            'nameLocaleKey' => $definition['name'],
            'abbrevLocaleKey' => $definition['abbrev'],
            'name' => $name,
            'abbrev' => $abbrev,
        ]);
        $group->save();
        return (int) $group->getKey();
    }
}
