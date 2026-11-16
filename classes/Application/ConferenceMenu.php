<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * The "Conferences" item in the primary navigation menu of a journal
 * (PUB-05): OJS navigation menus in the application, nothing in tests.
 */
interface ConferenceMenu
{
    public const ITEM_TYPE = 'NMI_TYPE_CONFERENCE_EDITIONS';

    /** Whether the primary menu of the journal holds the item. */
    public function hasItem(int $contextId): bool;

    /**
     * Puts the item first in the primary menu.
     *
     * @return bool false when the journal has no primary menu
     */
    public function addItem(int $contextId): bool;

    /** Drops the cached menus of the journal, so that new titles show. */
    public function forget(int $contextId): void;
}
