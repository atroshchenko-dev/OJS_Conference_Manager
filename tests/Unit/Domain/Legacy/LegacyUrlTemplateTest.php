<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Legacy;

use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyMap;
use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyUrlTemplate;
use PHPUnit\Framework\TestCase;

final class LegacyUrlTemplateTest extends TestCase
{
    public function testDefaultsMatchOcsAddresses(): void
    {
        [$templates, $errors] = LegacyUrlTemplate::parseAll(LegacyUrlTemplate::DEFAULTS);
        self::assertSame([], $errors);
        $match = static function (string $url) use ($templates): ?array {
            foreach ($templates as $t) {
                if (($m = $t->match($url)) !== null) {
                    return $m;
                }
            }
            return null;
        };
        $paper = ['id' => 123, 'conference' => 'SRE', 'schedConf' => 'SRE-2025'];
        self::assertSame($paper, $match('/SRE/SRE-2025/paper/view/123'));
        self::assertSame($paper, $match('https://conf.example.edu/index.php/SRE/SRE-2025/paper/viewPaper/123/'));
        self::assertSame($paper, $match('/SRE/SRE-2025/paper/download/123/456?x=1'));
        self::assertSame($paper, $match('SRE/SRE-2025/paper/view/123/456#top'));
        self::assertNull($match('/SRE/SRE-2025/paper/view/abc'));
        self::assertNull($match('/SRE/article/view/123'), 'an OJS page');
        self::assertNull($match('/SRE/SRE-2025/schedConf/presentations'));
    }

    public function testBadTemplates(): void
    {
        self::assertSame('id', LegacyUrlTemplate::parse('{conference}/paper/view')[1]);
        self::assertSame('fixed', LegacyUrlTemplate::parse('{conference}/{schedConf}/{id}')[1]);
        self::assertSame('placeholder', LegacyUrlTemplate::parse('{conf}/paper/{id}')[1]);
        self::assertSame('repeated', LegacyUrlTemplate::parse('{id}/paper/{id}')[1]);
        self::assertSame('segment', LegacyUrlTemplate::parse('paper/vi ew/{id}')[1]);
        self::assertSame('empty', LegacyUrlTemplate::parse('  / ')[1]);
        [$t] = LegacyUrlTemplate::parse('/archive/{any}/{id}/');
        self::assertSame('archive/{any}/{id}', $t?->pattern);
        self::assertSame(['id' => 7, 'conference' => null, 'schedConf' => null], $t?->match('/archive/2019/7'));
        [, $errors] = LegacyUrlTemplate::parseAll(['paper/{id}', 'bad']);
        self::assertSame([2 => 'id'], $errors);
    }

    public function testServerMaps(): void
    {
        [$templates] = LegacyUrlTemplate::parseAll(['{conference}/{schedConf}/paper/view/{id}', 'archive/{id}']);
        $papers = [
            ['id' => 123, 'conference' => 'SRE', 'schedConf' => 'SRE-2025', 'target' => 'https://j.example.org/SRE/article/view/45'],
            ['id' => 124, 'conference' => 'SRE', 'schedConf' => 'SRE-2025', 'target' => 'https://j.example.org/SRE/article/view/46'],
        ];
        self::assertSame([
            'SRE/SRE-2025/123' => 'https://j.example.org/SRE/article/view/45',
            '123' => 'https://j.example.org/SRE/article/view/45',
            'SRE/SRE-2025/124' => 'https://j.example.org/SRE/article/view/46',
            '124' => 'https://j.example.org/SRE/article/view/46',
        ], LegacyMap::entries($templates, $papers));

        $nginx = LegacyMap::nginx($templates, $papers, 'test');
        self::assertStringContainsString('"SRE/SRE-2025/123" "https://j.example.org/SRE/article/view/45";', $nginx);
        self::assertStringContainsString('location ~ "^/(?:index\.php/)?([^/]+)/([^/]+)/paper/view/(\d+)/?$" {', $nginx);
        self::assertStringContainsString('set $cm_legacy_key "$1/$2/$3";', $nginx);
        self::assertStringContainsString('set $cm_legacy_key "$1";', $nginx);

        $apache = LegacyMap::apache($templates, $papers, 'test', '/etc/apache2/x.txt');
        self::assertStringContainsString("SRE/SRE-2025/124 https://j.example.org/SRE/article/view/46\n", $apache['map']);
        self::assertStringContainsString('RewriteRule "^/(?:index\.php/)?([^/]+)/([^/]+)/paper/view/(\d+)/?$" "${cmlegacy:$1/$2/$3}" [R=301,L,NE]', $apache['rules']);
    }
}
