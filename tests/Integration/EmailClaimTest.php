<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\EmailClaimService;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEmailClaimRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Security audit 0.33: a new account with someone's address does not see
 * the records made for that address until the address is confirmed.
 */
final class EmailClaimTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private EmailClaimService $claims;
    private int $edition;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->ctx = new ContextInfo(1, 'uk', ['uk']);
        [$e, $errors] = (new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns()))
            ->create($this->ctx, EditionTest::validInput(['path' => 'conf-2027']));
        self::assertSame([], $errors->toArray());
        $this->edition = (int) $e?->id;
        foreach ([1, 2] as $id) {
            $this->db->table('users')->insert(['user_id' => $id]);
        }
        $this->claims = new EmailClaimService(new DbEmailClaimRepository($this->db));
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');
    }

    private function record(string $email, ?int $userId = null): int
    {
        return (int) $this->db->table('conf_participants')->insertGetId([
            'context_id' => 1, 'edition_id' => $this->edition, 'user_id' => $userId, 'email' => $email,
            'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00',
        ]);
    }

    public function testAddressIsMatchedOnlyAfterConfirmation(): void
    {
        $id = $this->record('anna@example.org');
        self::assertSame('', $this->claims->matchEmail(1, 'Anna@Example.org', false), 'unconfirmed address of a new account');
        self::assertSame('anna@example.org', $this->claims->matchEmail(1, 'Anna@Example.org', true), 'validated by OJS');
        self::assertTrue($this->claims->hasUnclaimed($this->ctx, 1, 'anna@example.org', false));
        self::assertFalse($this->claims->hasUnclaimed($this->ctx, 1, 'anna@example.org', true));
        self::assertFalse($this->claims->hasUnclaimed($this->ctx, 1, 'other@example.org', false));

        $token = $this->claims->request(1, 'anna@example.org', $this->now);
        self::assertNotNull($token);
        self::assertNull($this->claims->request(1, 'anna@example.org', $this->now->modify('+5 minutes')), 'one letter in 10 minutes');
        self::assertNotNull($this->claims->request(1, 'anna@example.org', $this->now->modify('+11 minutes')), 'a new letter replaces the old link');
        self::assertFalse($this->claims->confirm(1, 'anna@example.org', (string) $token, $this->now->modify('+12 minutes')), 'the replaced link');
        $token = $this->claims->request(1, 'anna@example.org', $this->now->modify('+30 minutes'));

        self::assertFalse($this->claims->confirm(2, 'anna@example.org', (string) $token, $this->now->modify('+31 minutes')), 'another account');
        self::assertFalse($this->claims->confirm(1, 'other@example.org', (string) $token, $this->now->modify('+31 minutes')), 'the account changed its address');
        self::assertFalse($this->claims->confirm(1, 'anna@example.org', 'x' . $token, $this->now->modify('+31 minutes')));
        self::assertFalse($this->claims->confirm(1, 'anna@example.org', (string) $token, $this->now->modify('+8 days')), 'expired');
        self::assertSame(null, $this->db->table('conf_participants')->where('id', $id)->value('user_id'));

        self::assertTrue($this->claims->confirm(1, 'anna@example.org', (string) $token, $this->now->modify('+1 hour')));
        self::assertSame(1, (int) $this->db->table('conf_participants')->where('id', $id)->value('user_id'), 'records go to the account');
        self::assertSame('anna@example.org', $this->claims->matchEmail(1, 'anna@example.org', false));
        self::assertFalse($this->claims->hasUnclaimed($this->ctx, 1, 'anna@example.org', false));
        self::assertFalse($this->claims->confirm(1, 'anna@example.org', (string) $token, $this->now->modify('+2 hours')), 'used once');
        self::assertNull($this->claims->request(1, 'anna@example.org', $this->now->modify('+3 hours')), 'nothing to confirm');
    }

    public function testSelfRegistrationDoesNotTakeAnUnconfirmedRecord(): void
    {
        $id = $this->record('anna@example.org');
        $repo = new DbParticipantRepository($this->db);
        $person = ['userId' => 2, 'email' => 'anna@example.org', 'matchEmail' => '', 'givenName' => ['uk' => 'Анна'], 'familyName' => ['uk' => 'Іванова'],
            'affiliation' => null, 'country' => null, 'orcid' => null, 'position' => null];
        self::assertNotNull($repo->addListener(1, $this->edition, $person, null, ParticipationForm::Online, 'v1', $this->now));
        self::assertNull($this->db->table('conf_participants')->where('id', $id)->value('user_id'), 'the record stays without an account');
        self::assertSame([], $repo->ofPerson(1, 2, ''), 'and the account does not see it');

        $other = $this->record('bob@example.org');
        $person = ['userId' => 1, 'email' => 'bob@example.org', 'matchEmail' => 'bob@example.org'] + $person;
        self::assertNotNull($repo->addListener(1, $this->edition, $person, null, ParticipationForm::Online, 'v1', $this->now));
        self::assertSame(1, (int) $this->db->table('conf_participants')->where('id', $other)->value('user_id'), 'a confirmed address takes it');
    }
}
