<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * PUB-05: the public part of a journal as a conference site. In this mode
 * the texts of OJS say "conference" and "proceedings" instead of
 * "journal" and "issue", the home page lists the events, and the primary
 * menu can hold the "Conferences" item. Off by default, so that a journal
 * that also publishes ordinary issues keeps its wording.
 */
final class SiteModeService
{
    public const SETTING = 'siteMode';

    public function __construct(
        private readonly ?SettingsStore $settings,
        private readonly ?ConferenceMenu $menu = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function isConferenceSite(int $contextId): bool
    {
        return self::isOn($this->settings?->get($contextId, self::SETTING));
    }

    /** Reads the stored setting; the plugin reads it itself while it registers. */
    public static function isOn(mixed $setting): bool
    {
        return is_array($setting) && filter_var($setting['conferenceSite'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{conferenceSite: bool, menuItem: bool}
     */
    public function state(ContextInfo $context): array
    {
        return [
            'conferenceSite' => $this->isConferenceSite($context->id),
            'menuItem' => $this->menu?->hasItem($context->id) ?? false,
        ];
    }

    /**
     * @param array<string, mixed> $input {conferenceSite?: bool, menuItem?: bool}
     * @return array{conferenceSite: bool, menuItem: bool}
     */
    public function save(ContextInfo $context, array $input, ?int $actorId): array
    {
        $before = $this->state($context);
        if (array_key_exists('conferenceSite', $input)) {
            $on = filter_var($input['conferenceSite'], FILTER_VALIDATE_BOOLEAN);
            $this->settings?->set($context->id, self::SETTING, ['conferenceSite' => $on]);
        }
        if (filter_var($input['menuItem'] ?? false, FILTER_VALIDATE_BOOLEAN) && !$before['menuItem']) {
            $this->menu?->addItem($context->id);
        }
        // Menu titles are cached with the old wording.
        $this->menu?->forget($context->id);
        $after = $this->state($context);
        if ($after !== $before) {
            $this->audit?->log($context->id, $actorId, 'site_mode', null, 'update', $before, $after);
        }
        return $after;
    }
}
