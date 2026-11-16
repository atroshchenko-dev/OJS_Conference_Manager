<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\ConferenceMenu;
use Illuminate\Support\Facades\Cache;
use PKP\db\DAORegistry;
use PKP\navigationMenu\NavigationMenuDAO;
use PKP\navigationMenu\NavigationMenuItemAssignmentDAO;
use PKP\navigationMenu\NavigationMenuItemDAO;

/** PUB-05: the "Conferences" item in the OJS navigation menus of a journal. */
final class NavigationMenuGateway implements ConferenceMenu
{
    public const TITLE_KEY = 'plugins.generic.conferenceManager.menu.editions';

    public function hasItem(int $contextId): bool
    {
        $menu = $this->primaryMenu($contextId);
        if ($menu === null) {
            return false;
        }
        foreach ($this->assignments()->getByMenuId((int) $menu->getId())->toArray() as $a) {
            if ($this->items()->getById($a->getMenuItemId())?->getType() === self::ITEM_TYPE) {
                return true;
            }
        }
        return false;
    }

    public function addItem(int $contextId): bool
    {
        $menu = $this->primaryMenu($contextId);
        if ($menu === null) {
            return false;
        }
        $menuId = (int) $menu->getId();
        $items = $this->items();
        $item = null;
        foreach ($items->getByType(self::ITEM_TYPE, $contextId)->toArray() as $existing) {
            $item = $existing;
            break;
        }
        if ($item === null) {
            $item = $items->newDataObject();
            $item->setPath('');
            $item->setContextId($contextId);
            $item->setType(self::ITEM_TYPE);
            $id = $items->insertObject($item);
            $items->updateSetting($id, 'titleLocaleKey', self::TITLE_KEY);
            $item = $items->getById($id);
        }
        if ($item === null) {
            return false;
        }
        // First in the top level: the other top-level items move one place on.
        foreach ($this->assignments()->getByMenuIdAndParentId($menuId, null)->toArray() as $a) {
            $a->setSequence($a->getSequence() + 1);
            $this->assignments()->updateObject($a);
        }
        $assignment = $this->assignments()->newDataObject();
        $assignment->setMenuId($menuId);
        $assignment->setMenuItemId((int) $item->getId());
        $assignment->setParentId(null);
        $assignment->setSequence(0);
        $this->assignments()->insertObject($assignment);
        $this->forget($contextId);
        return true;
    }

    public function forget(int $contextId): void
    {
        /** @var NavigationMenuDAO $dao */
        $dao = DAORegistry::getDAO('NavigationMenuDAO');
        foreach ($dao->getByContextId($contextId)->toArray() as $menu) {
            Cache::forget('navigationMenu-' . (int) $menu->getId());
        }
    }

    private function primaryMenu(int $contextId): ?\PKP\navigationMenu\NavigationMenu
    {
        /** @var NavigationMenuDAO $dao */
        $dao = DAORegistry::getDAO('NavigationMenuDAO');
        foreach ($dao->getByArea($contextId, 'primary')->toArray() as $menu) {
            return $menu;
        }
        return null;
    }

    private function items(): NavigationMenuItemDAO
    {
        /** @var NavigationMenuItemDAO $dao */
        $dao = DAORegistry::getDAO('NavigationMenuItemDAO');
        return $dao;
    }

    private function assignments(): NavigationMenuItemAssignmentDAO
    {
        /** @var NavigationMenuItemAssignmentDAO $dao */
        $dao = DAORegistry::getDAO('NavigationMenuItemAssignmentDAO');
        return $dao;
    }
}
