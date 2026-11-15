<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Communication;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\InvitationLetterHtml;
use PHPUnit\Framework\TestCase;

final class InvitationLetterHtmlTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function values(array $override = []): array
    {
        return $override + [
            'institution' => 'Університет', 'address' => 'вул. Тестова, 1, Київ', 'legalIdLine' => 'ЄДРПОУ: 12345678',
            'contacts' => 'conf@example.org', 'numberLine' => '№ conf-2027/7 від 01.03.2027',
            'addresseeName' => 'Олена Бондар', 'addresseeAffiliation' => 'Ліцей № 1', 'title' => 'Лист-запрошення',
            'salutation' => 'Шановний(-а) Олена Бондар!', 'paragraphs' => ['Перший абзац.', '', 'Другий абзац.'],
            'signerPosition' => 'Ректор', 'signerName' => 'Іван Петренко',
        ];
    }

    public function testLetterParts(): void
    {
        $html = InvitationLetterHtml::render(self::values());
        foreach (['Університет', 'ЄДРПОУ: 12345678', '№ conf-2027/7 від 01.03.2027', 'Олена Бондар', 'Лист-запрошення', 'Ректор', 'Іван Петренко'] as $part) {
            self::assertStringContainsString($part, $html);
        }
        // Empty paragraphs are left out.
        self::assertSame(2, substr_count($html, '<p class="text">'));
    }

    public function testEscapesEverything(): void
    {
        $html = InvitationLetterHtml::render(self::values(['addresseeName' => '<script>x</script>', 'paragraphs' => ['<b>a</b> & "b"']]));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;b&gt;a&lt;/b&gt; &amp; &quot;b&quot;', $html);
    }

    public function testOptionalLinesAreLeftOut(): void
    {
        $html = InvitationLetterHtml::render(self::values(['address' => '', 'legalIdLine' => '', 'contacts' => '', 'salutation' => '', 'addresseeAffiliation' => '']));
        self::assertStringNotContainsString('class="small"', $html);
        self::assertStringNotContainsString('class="salutation"', $html);
        self::assertStringNotContainsString('<br>', $html);
    }
}
