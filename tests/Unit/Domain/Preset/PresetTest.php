<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Preset;

use APP\plugins\generic\conferenceManager\classes\Application\PresetLibrary;
use APP\plugins\generic\conferenceManager\classes\Application\PresetSource;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Preset\Preset;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Files\JsonPresetSource;
use PHPUnit\Framework\TestCase;

/** DST-07: preset files of the package and of a site. */
final class PresetTest extends TestCase
{
    private static function packageDir(): string
    {
        return dirname(__DIR__, 4) . '/presets';
    }

    public function testEveryPackagePresetIsValid(): void
    {
        $library = new PresetLibrary(new JsonPresetSource(['package' => self::packageDir()]));
        self::assertSame([], $library->problems());
        $codes = array_map(static fn (Preset $p): string => $p->code, $library->all());
        sort($codes);
        self::assertSame(['generic-conference', 'ua-ippo', 'ua-man', 'ua-research-institute', 'ua-university-private', 'ua-university-state'], $codes);
        foreach ($library->all() as $p) {
            self::assertNotNull($p->name->get('uk'), "{$p->code} has a Ukrainian name");
            self::assertNotNull($p->name->get('en'), "{$p->code} has an English name");
            self::assertNotNull($p->description->get('uk'), "{$p->code} has a Ukrainian description");
            self::assertNotNull($p->description->get('en'), "{$p->code} has an English description");
            self::assertSame(basename((string) realpath(self::packageDir() . "/{$p->code}.json"), '.json'), $p->code, 'file name is the code');
        }
    }

    public function testSuitsTypeAndJurisdiction(): void
    {
        $library = new PresetLibrary(new JsonPresetSource(['package' => self::packageDir()]));
        $ippo = array_map(static fn (Preset $p): string => $p->code, $library->suitable(InstitutionType::Ippo, 'UA'));
        sort($ippo);
        self::assertSame(['ua-ippo', 'ua-man'], $ippo);
        self::assertSame(['generic-conference'], array_map(static fn (Preset $p): string => $p->code, $library->suitable(InstitutionType::University, 'generic')));
        self::assertCount(5, $library->suitable(null, 'UA'));
    }

    public function testErrorsNameTheProblem(): void
    {
        [$p, $errors] = Preset::parse([
            'schema' => 2,
            'code' => 'Bad Code',
            'institutionTypes' => ['castle'],
            'edition' => ['eventType' => 'party', 'path' => 'x'],
            'categories' => [['code' => 'own_staff'], ['code' => 'own_staff'], ['code' => 'mine']],
            'tariffs' => [['category' => 'nobody', 'material' => 'paper', 'amount' => '1.00', 'currency' => 'UAH'], ['category' => 'own_staff', 'material' => 'paper', 'amount' => '1,5', 'currency' => 'UAH']],
            'paymentRules' => [['mode' => 'free'], ['category' => null, 'mode' => 'free'], ['mode' => 'cash']],
        ]);
        self::assertNull($p);
        $text = implode("\n", $errors);
        foreach (['schema must be 1', 'code must be', 'name needs', 'unknown institution type "castle"', 'edition.eventType', 'edition.path is not', 'repeats', 'name is needed for the own code "mine"', 'tariffs[0]: category', 'tariffs[1]: amount', 'paymentRules[1]: a second rule', 'paymentRules[2]: mode'] as $part) {
            self::assertStringContainsString($part, $text);
        }
        self::assertSame([null, ['the file is not a JSON object']], Preset::parse('text'));
    }

    public function testSiteFileCannotReplaceAPackagePreset(): void
    {
        $valid = ['schema' => 1, 'code' => 'ua-ippo', 'name' => ['uk' => 'Мій'], 'categories' => [['code' => 'mine', 'name' => ['uk' => 'Мої']]]];
        $source = new class ($valid) implements PresetSource {
            /** @param array<string, mixed> $valid */
            public function __construct(private readonly array $valid)
            {
            }

            public function files(): array
            {
                return [
                    ['file' => '/srv/files/conferenceManager/presets/broken.json', 'source' => 'site', 'data' => null, 'error' => 'invalid JSON: Syntax error'],
                    ['file' => '/srv/files/conferenceManager/presets/copy.json', 'source' => 'site', 'data' => $this->valid, 'error' => null],
                    ['file' => '/srv/files/conferenceManager/presets/own.json', 'source' => 'site', 'data' => ['code' => 'own-school'] + $this->valid, 'error' => null],
                    ['file' => '/plugin/presets/ua-ippo.json', 'source' => 'package', 'data' => json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/presets/ua-ippo.json'), true), 'error' => null],
                ];
            }
        };
        $library = new PresetLibrary($source);
        self::assertSame('package', $library->find('ua-ippo')?->source);
        self::assertSame('site', $library->find('own-school')?->source);
        self::assertSame(['broken.json', 'copy.json'], array_column($library->problems(), 'file'), 'only file names are shown');
        self::assertStringContainsString('already used by ua-ippo.json', $library->problems()[1]['errors'][0]);
    }
}
