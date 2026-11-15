<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionCopyService;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\PartnerService;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Files\PublicLogoStore;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPartnerRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * INS-04: co-organisers and partners of an edition with logos, order and
 * the payment account of a co-organiser; EDN-09 copies them with the logos.
 */
final class PartnersTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private EditionService $editions;
    private PartnerService $partners;
    private string $dir;
    private int $edition;
    private int $partnerAccount;
    private int $bankAccount;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Вісник']);
        $registry = JurisdictionRegistry::withBuiltIns();
        $profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), $registry);
        [$profile, $errors] = $profiles->save($this->ctx, [
            'jurisdiction' => 'UA',
            'institutionType' => 'university',
            'ownership' => 'state',
            'legalId' => '02070944',
            'name' => ['uk' => 'Університет'],
            'address' => ['uk' => 'м. Київ, вул. Освіти, 1'],
            'paymentAccounts' => [
                ['kind' => 'bank', 'recipientName' => 'Університет', 'iban' => 'UA213223130000026007233566001', 'bankName' => 'Банк'],
                ['kind' => 'partner', 'label' => 'Рахунок ГО «Наука»', 'recipientName' => 'ГО «Наука»', 'iban' => 'UA903052992990004149123456789', 'bankName' => 'Банк'],
            ],
        ]);
        self::assertSame([], $errors->toArray());
        foreach ($profile?->activePaymentAccounts() ?? [] as $a) {
            $a->kind->value === 'partner' ? $this->partnerAccount = (int) $a->id : $this->bankAccount = (int) $a->id;
        }
        $this->editions = new EditionService(new DbEditionRepository($this->db), $registry);
        [$e, $errors] = $this->editions->create($this->ctx, EditionTest::validInput(['path' => 'conf-2027']));
        self::assertSame([], $errors->toArray());
        $this->edition = (int) $e?->id;
        $this->dir = sys_get_temp_dir() . '/cm-partners-' . bin2hex(random_bytes(4));
        $this->partners = new PartnerService(
            new DbPartnerRepository($this->db),
            new DbEditionRepository($this->db),
            $profiles,
            new PublicLogoStore($this->dir),
            new AuditLogger($this->db),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function add(array $input): int
    {
        [$p, $errors] = $this->partners->save($this->ctx, $this->edition, null, $input, 1) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        return (int) $p?->id;
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cmlogo');
        file_put_contents($path, $content);
        return $path;
    }

    public function testValidation(): void
    {
        [$p, $errors] = $this->partners->save($this->ctx, $this->edition, null, [
            'role' => 'boss', 'name' => ['en' => 'Only English'], 'url' => 'javascript:alert(1)',
        ]) ?? [null, null];
        self::assertNull($p);
        self::assertSame(['role', 'name', 'url'], array_keys($errors?->toArray() ?? []));

        // Only an active "partner" account, and only for a co-organiser.
        [, $errors] = $this->partners->save($this->ctx, $this->edition, null, ['role' => 'co_organizer', 'name' => ['uk' => 'ГО'], 'paymentAccountId' => $this->bankAccount]) ?? [null, null];
        self::assertSame(['plugins.generic.conferenceManager.partner.error.account'], $errors?->toArray()['paymentAccountId']);
        [, $errors] = $this->partners->save($this->ctx, $this->edition, null, ['role' => 'sponsor', 'name' => ['uk' => 'ГО'], 'paymentAccountId' => $this->partnerAccount]) ?? [null, null];
        self::assertSame(['plugins.generic.conferenceManager.partner.error.accountRole'], $errors?->toArray()['paymentAccountId']);

        self::assertNull($this->partners->save($this->ctx, 999, null, ['name' => ['uk' => 'X']]));
        self::assertNull($this->partners->save(new ContextInfo(2, 'uk', ['uk']), $this->edition, null, ['name' => ['uk' => 'X']]), 'another journal');
    }

    public function testListOrderMoveAndDelete(): void
    {
        $a = $this->add(['role' => 'partner', 'name' => ['uk' => 'Партнер А', 'en' => 'Partner A'], 'url' => 'https://a.example.org']);
        $b = $this->add(['role' => 'co_organizer', 'name' => ['uk' => 'ГО «Наука»'], 'paymentAccountId' => (string) $this->partnerAccount]);
        $c = $this->add(['role' => 'info_partner', 'name' => ['uk' => 'Газета']]);
        self::assertSame([$a, $b, $c], array_map(static fn ($p) => $p->id, $this->partners->ofEdition($this->ctx, $this->edition) ?? []));

        $this->partners->move($this->ctx, $this->edition, $c, -1);
        self::assertSame([$a, $c, $b], array_map(static fn ($p) => $p->id, $this->partners->ofEdition($this->ctx, $this->edition) ?? []));
        $this->partners->move($this->ctx, $this->edition, $a, -1);
        self::assertSame([$a, $c, $b], array_map(static fn ($p) => $p->id, $this->partners->ofEdition($this->ctx, $this->edition) ?? []), 'the first stays first');

        // An edit keeps what was not sent.
        [$p] = $this->partners->save($this->ctx, $this->edition, $a, ['url' => 'https://a2.example.org']) ?? [null];
        self::assertSame('Partner A', $p?->name->resolve('en', 'uk'));
        self::assertSame(0, $p?->seq);

        $groups = $this->partners->publicGroups($this->ctx, $this->edition, 'en', static fn (string $k): string => '/logo/' . $k);
        self::assertSame(['co_organizer', 'partner', 'info_partner'], array_column($groups, 'role'));
        self::assertTrue($groups[0]['partners'][0]['receivesFees']);
        self::assertSame('ГО «Наука»', $groups[0]['partners'][0]['name'], 'the primary locale stands in');
        self::assertSame(['name' => 'Partner A', 'url' => 'https://a2.example.org', 'logoUrl' => null, 'receivesFees' => false], $groups[1]['partners'][0]);

        self::assertTrue($this->partners->delete($this->ctx, $this->edition, $c, 1));
        $left = $this->partners->ofEdition($this->ctx, $this->edition) ?? [];
        self::assertSame([[$a, 0], [$b, 1]], array_map(static fn ($p) => [$p->id, $p->seq], $left));
        self::assertFalse($this->partners->delete($this->ctx, $this->edition, $c));
        self::assertSame(1, $this->db->table('conf_audit_log')->where('entity', 'partner')->where('action', 'delete')->count());
    }

    public function testLogos(): void
    {
        $id = $this->add(['name' => ['uk' => 'Партнер']]);
        [$p, $errors] = $this->partners->setLogo($this->ctx, $this->edition, $id, $this->file('<svg onload="alert(1)"></svg>')) ?? [null, null];
        self::assertNull($p);
        self::assertSame(['logo' => ['plugins.generic.conferenceManager.partner.error.logoType']], $errors?->toArray());
        [, $errors] = $this->partners->setLogo($this->ctx, $this->edition, $id, $this->file('')) ?? [null, null];
        self::assertSame(['logo' => ['plugins.generic.conferenceManager.partner.error.logoEmpty']], $errors?->toArray());
        [, $errors] = $this->partners->setLogo($this->ctx, $this->edition, $id, $this->file("\x89PNG\r\n\x1a\n" . str_repeat('x', 1048577))) ?? [null, null];
        self::assertSame(['logo' => ['plugins.generic.conferenceManager.partner.error.logoTooLarge']], $errors?->toArray());

        [$p] = $this->partners->setLogo($this->ctx, $this->edition, $id, $this->file("\x89PNG\r\n\x1a\nfirst")) ?? [null];
        $first = (string) $p?->logoPath;
        self::assertMatchesRegularExpression('#^1/' . $this->edition . '-' . $id . '-[0-9a-f]{12}\.png$#', $first);
        self::assertFileExists($this->dir . '/' . $first);
        [$p] = $this->partners->setLogo($this->ctx, $this->edition, $id, $this->file("\xFF\xD8\xFF\xE0second")) ?? [null];
        $second = (string) $p?->logoPath;
        self::assertStringEndsWith('.jpg', $second);
        self::assertFileDoesNotExist($this->dir . '/' . $first, 'the old logo is removed');

        $this->partners->removeLogo($this->ctx, $this->edition, $id);
        self::assertFileDoesNotExist($this->dir . '/' . $second);
        self::assertNull($this->partners->ofEdition($this->ctx, $this->edition)[0]->logoPath);
    }

    public function testCopyTakesPartnersWithLogos(): void
    {
        $id = $this->add(['role' => 'co_organizer', 'name' => ['uk' => 'ГО «Наука»'], 'url' => 'https://nauka.example.org', 'paymentAccountId' => $this->partnerAccount]);
        $this->add(['role' => 'sponsor', 'name' => ['uk' => 'Спонсор']]);
        $this->partners->setLogo($this->ctx, $this->edition, $id, $this->file("\x89PNG\r\n\x1a\nlogo"));
        [$e, $errors] = $this->editions->copy($this->ctx, $this->edition, [
            'path' => 'conf-2028', 'startsAt' => '2028-05-18 09:00', 'endsAt' => '2028-05-18 18:00',
            'cfpOpensAt' => '2028-01-15', 'abstractDeadline' => '2028-03-01', 'paperDeadline' => '2028-04-01',
        ]) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        $target = (int) $e?->id;
        $copy = new EditionCopyService(new DbEditionRepository($this->db), new DbFeeRepository($this->db), new DbPaymentRepository($this->db), null, null, null, $this->partners);
        $out = $copy->copyParts($this->ctx, $this->edition, $target, ['partners']);
        self::assertSame(2, $out['partners'] ?? null);

        $copied = $this->partners->ofEdition($this->ctx, $target) ?? [];
        self::assertSame(['ГО «Наука»', 'Спонсор'], array_map(static fn ($p) => $p->name->resolve('uk', 'uk'), $copied));
        self::assertSame($this->partnerAccount, $copied[0]->paymentAccountId);
        self::assertNotSame($this->partners->ofEdition($this->ctx, $this->edition)[0]->logoPath, $copied[0]->logoPath, 'its own logo file');
        self::assertStringStartsWith('1/' . $target . '-', (string) $copied[0]->logoPath);
        self::assertSame("\x89PNG\r\n\x1a\nlogo", file_get_contents($this->dir . '/' . $copied[0]->logoPath));

        // Deleting the copy's partner leaves the source logo alone.
        $this->partners->delete($this->ctx, $target, (int) $copied[0]->id);
        self::assertFileExists($this->dir . '/' . $this->partners->ofEdition($this->ctx, $this->edition)[0]->logoPath);
        self::assertSame(0, $copy->copyParts($this->ctx, $this->edition, $target, ['partners'])['partners'] ?? null, 'the target already has partners');
    }
}
