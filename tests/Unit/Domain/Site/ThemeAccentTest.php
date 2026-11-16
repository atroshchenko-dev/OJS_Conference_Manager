<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Site;

use APP\plugins\generic\conferenceManager\classes\Domain\Site\ThemeAccent;
use PHPUnit\Framework\TestCase;

final class ThemeAccentTest extends TestCase
{
    public function testOnlyRealColoursAreAccepted(): void
    {
        self::assertNull(ThemeAccent::fromColour(null));
        self::assertNull(ThemeAccent::fromColour(false));
        self::assertNull(ThemeAccent::fromColour(''));
        self::assertNull(ThemeAccent::fromColour('red'));
        self::assertNull(ThemeAccent::fromColour('#12345'));
        self::assertNull(ThemeAccent::fromColour('#1E6292;}body{display:none'));
        self::assertSame('#1e6292', ThemeAccent::fromColour(' #1E6292 ')?->accent());
        self::assertSame('#aabbcc', ThemeAccent::fromColour('abc')?->accent());
    }

    public function testDefaultThemeBlueIsUsedAsIs(): void
    {
        $accent = ThemeAccent::fromColour('#1E6292');
        self::assertNotNull($accent);
        self::assertSame('#fff', $accent->onAccent());
        self::assertSame('#1e6292', $accent->text());
        self::assertSame(':root{--cm-accent:#1e6292;--cm-on-accent:#fff;--cm-accent-text:#1e6292;--cm-accent-surface:#edf2f6}', $accent->css());
    }

    public function testLightColourGetsDarkButtonTextAndReadableLinkText(): void
    {
        $accent = ThemeAccent::fromColour('#F7BC4A');
        self::assertNotNull($accent);
        self::assertSame('#222222', $accent->onAccent());
        self::assertGreaterThanOrEqual(4.5, ThemeAccent::contrast($accent->text(), '#ffffff'));
        self::assertNotSame('#f7bc4a', $accent->text());
    }
}
