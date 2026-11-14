<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use PHPUnit\Framework\TestCase;

final class LocalizedTextTest extends TestCase
{
    public function testDropsEmptyValuesAndResolvesFallbacks(): void
    {
        $text = new LocalizedText(['uk' => ' Назва ', 'en' => '   ', 'pl' => 'Nazwa', 5 => 'x']);
        self::assertSame(['pl' => 'Nazwa', 'uk' => 'Назва'], $text->toArray());
        self::assertSame('Назва', $text->resolve('uk'));
        self::assertSame('Назва', $text->resolve('en', 'uk'));
        self::assertSame('Nazwa', $text->resolve('en'));
        self::assertNull((new LocalizedText())->resolve('uk'));
    }
}
