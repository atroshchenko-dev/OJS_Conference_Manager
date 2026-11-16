<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Application;

use APP\plugins\generic\conferenceManager\classes\Application\ConferenceMenu;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\SettingsStore;
use APP\plugins\generic\conferenceManager\classes\Application\SiteModeService;
use PHPUnit\Framework\TestCase;

/** PUB-05: the public part of a journal as a conference site. */
final class SiteModeServiceTest extends TestCase
{
    public function testOffByDefaultThenOnWithTheMenuItemOnce(): void
    {
        $settings = new class () implements SettingsStore {
            /** @var array<string, mixed> */
            public array $values = [];

            public function get(int $contextId, string $name): mixed
            {
                return $this->values[$contextId . '/' . $name] ?? null;
            }

            public function set(int $contextId, string $name, mixed $value): void
            {
                $this->values[$contextId . '/' . $name] = $value;
            }
        };
        $menu = new class () implements ConferenceMenu {
            /** @var array<int, int> */
            public array $added = [];
            public int $forgotten = 0;

            public function hasItem(int $contextId): bool
            {
                return isset($this->added[$contextId]);
            }

            public function addItem(int $contextId): bool
            {
                $this->added[$contextId] = ($this->added[$contextId] ?? 0) + 1;
                return true;
            }

            public function forget(int $contextId): void
            {
                $this->forgotten++;
            }
        };
        $service = new SiteModeService($settings, $menu);
        $ctx = $this->context(1);

        self::assertSame(['conferenceSite' => false, 'menuItem' => false], $service->state($ctx));
        self::assertFalse($service->isConferenceSite(1));

        self::assertSame(['conferenceSite' => true, 'menuItem' => true], $service->save($ctx, ['conferenceSite' => true, 'menuItem' => true], 7));
        $service->save($ctx, ['menuItem' => true], 7);
        self::assertSame(1, $menu->added[1], 'the item is added once');
        self::assertTrue($service->isConferenceSite(1));
        self::assertFalse($service->isConferenceSite(2), 'another journal keeps its wording');
        self::assertSame(2, $menu->forgotten, 'cached menus are dropped on every change');

        self::assertSame(['conferenceSite' => false, 'menuItem' => true], $service->save($ctx, ['conferenceSite' => 'false'], 7));
        self::assertTrue($service->save($ctx, ['menuItem' => false], 7)['menuItem'], 'the item is removed in the menu editor of OJS, not here');
    }

    private function context(int $id): ContextInfo
    {
        return new ContextInfo($id, 'uk', ['uk']);
    }
}
