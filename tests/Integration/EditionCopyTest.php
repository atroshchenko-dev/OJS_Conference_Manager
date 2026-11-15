<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionCopyService;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\Room;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * EDN-09: a copy of last year's edition with its categories, tariffs
 * (early-bird dates moved by a year), payment rules, rooms and settings.
 */
final class EditionCopyTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private EditionService $editions;
    private FeeService $fees;
    private EditionCopyService $copy;
    private FakeSettings $settings;
    private int $source;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Вісник']);
        $this->editions = new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $this->editions->create($this->ctx, EditionTest::validInput(['path' => 'conf-2027']));
        self::assertSame([], $errors->toArray());
        $this->source = (int) $e?->id;
        $this->fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $cats = [];
        foreach ($this->fees->applyPreset($this->ctx, $this->source, InstitutionType::University, static fn (string $k, string $l): string => $k) ?? [] as $c) {
            $cats[$c->code] = (int) $c->id;
        }
        [, $errors] = $this->fees->saveTariff($this->ctx, $this->source, null, [
            'categoryId' => $cats['students'], 'material' => 'listener', 'pricing' => 'flat', 'amount' => '100', 'currency' => 'UAH',
            'validFrom' => '2027-01-01 00:00', 'validTo' => '2027-03-31 23:59',
        ]) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        $payments = new DbPaymentRepository($this->db);
        $payments->saveRule(new PaymentRule(null, $this->source, null, PaymentMode::ExternalLink, null, 'https://pay.example.org', new LocalizedText(['uk' => 'Оплата карткою'])));
        $payments->saveRule(new PaymentRule(null, $this->source, $cats['students'], PaymentMode::Free));
        $program = new DbProgramRepository($this->db);
        $program->saveRoom(new Room(null, $this->source, 'Аудиторія 101', 40, null));
        $program->saveRoom(new Room(null, $this->source, 'Онлайн', null, 'https://meet.example.org/a'));
        $this->settings = new FakeSettings();
        $this->settings->set(1, 'reviewSettings', [$this->source => ['abstractFormId' => 3]]);
        $this->settings->set(1, 'abstractBook', [$this->source => ['title' => ['uk' => 'Збірник']]]);
        $this->copy = new EditionCopyService(new DbEditionRepository($this->db), new DbFeeRepository($this->db), $payments, $program, $this->settings, new AuditLogger($this->db));
    }

    private function newEdition(string $path, string $startsAt): int
    {
        [$e, $errors] = $this->editions->copy($this->ctx, $this->source, [
            'path' => $path, 'startsAt' => $startsAt, 'endsAt' => substr($startsAt, 0, 10) . ' 18:00',
            'cfpOpensAt' => '2028-01-15', 'abstractDeadline' => '2028-03-01', 'paperDeadline' => '2028-04-01',
        ]) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        return (int) $e?->id;
    }

    public function testEverythingComesAlongWithDatesMoved(): void
    {
        $target = $this->newEdition('conf-2028', '2028-05-18 09:00');
        $out = $this->copy->copyParts($this->ctx, $this->source, $target, EditionCopyService::PARTS, 1);
        self::assertNotNull($out);
        $sourceCats = (new DbFeeRepository($this->db))->categories($this->source);
        self::assertSame(count($sourceCats), $out['categories']);
        self::assertSame(364, $out['shiftDays'], '2028 is a leap year');
        self::assertSame(2, $out['paymentRules']);
        self::assertSame(2, $out['rooms']);
        self::assertSame(2, $out['settings']);

        $fees = new DbFeeRepository($this->db);
        $newCats = $fees->categories($target);
        self::assertSame(array_map(static fn ($c) => $c->code, $sourceCats), array_map(static fn ($c) => $c->code, $newCats));
        $byCode = [];
        foreach ($newCats as $c) {
            $byCode[$c->code] = (int) $c->id;
        }
        $tariffs = $fees->tariffs($target);
        self::assertSame(count($fees->tariffs($this->source)), count($tariffs));
        $dated = array_values(array_filter($tariffs, static fn ($t) => $t->validFrom !== null));
        self::assertCount(1, $dated);
        self::assertSame($byCode['students'], $dated[0]->categoryId);
        self::assertSame('2027-12-31', $dated[0]->validFrom?->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d'));
        self::assertSame(10000, $dated[0]->amount->minor);

        $rules = (new DbPaymentRepository($this->db))->rules($target);
        $byCategory = [];
        foreach ($rules as $r) {
            $byCategory[$r->categoryId ?? 0] = $r;
        }
        self::assertSame('https://pay.example.org', $byCategory[0]->externalUrl);
        self::assertSame('Оплата карткою', $byCategory[0]->instructions->resolve('uk', 'uk'));
        self::assertSame(PaymentMode::Free, $byCategory[$byCode['students']]->mode);

        self::assertSame(['Аудиторія 101', 'Онлайн'], array_map(static fn ($r) => $r->name, (new DbProgramRepository($this->db))->rooms($target)));
        self::assertSame(['abstractFormId' => 3], $this->settings->get(1, 'reviewSettings')[$target]);
        self::assertSame(['title' => ['uk' => 'Збірник']], $this->settings->get(1, 'abstractBook')[$target]);
        self::assertSame(1, $this->db->table('conf_audit_log')->where('action', 'copy_parts')->count());

        // A second run adds nothing twice.
        $again = $this->copy->copyParts($this->ctx, $this->source, $target, EditionCopyService::PARTS);
        self::assertSame(0, $again['categories'] + $again['tariffs'] + $again['paymentRules'] + $again['rooms'] + $again['settings']);
    }

    public function testOnlyTheChosenParts(): void
    {
        $target = $this->newEdition('conf-2028', '2028-05-20 09:00');
        $out = $this->copy->copyParts($this->ctx, $this->source, $target, ['paymentRules', 'rooms', 'bogus']);
        self::assertSame(0, $out['categories']);
        // Without the categories only the rule for everyone comes along.
        self::assertSame(1, $out['paymentRules']);
        self::assertSame(2, $out['rooms']);
        self::assertSame(0, $out['settings']);
        self::assertSame([], (new DbFeeRepository($this->db))->categories($target));
    }

    public function testOtherJournalAndSameEdition(): void
    {
        $target = $this->newEdition('conf-2028', '2028-05-20 09:00');
        self::assertNull($this->copy->copyParts(new ContextInfo(2, 'uk', ['uk'], []), $this->source, $target, EditionCopyService::PARTS));
        self::assertNull($this->copy->copyParts($this->ctx, $this->source, $this->source, EditionCopyService::PARTS));
        self::assertNull($this->copy->copyParts($this->ctx, 999, $target, EditionCopyService::PARTS));
    }
}
