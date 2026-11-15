<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ConfirmationRequest;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionDirectory;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionInfo;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionRegistrationService;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\AuthorInfo;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * REG-02…04 and EDN-03 on SQLite: questionnaire, checks on submit,
 * participants from authors, one-time confirmation links.
 */
final class RegistrationFlowTest extends TestCase
{
    private Connection $db;
    private SubmissionRegistrationService $service;
    private ContextInfo $ctx;
    private FakeSubmissions $submissions;
    private FakeNotifier $notifier;
    private string $consent;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('submissions')->insert([['note' => 's1'], ['note' => 's2']]);
        $this->db->table('authors')->insert([['note' => 'a1'], ['note' => 'a2'], ['note' => 'a3']]);
        $this->db->table('users')->insert([['note' => 'u1']]);

        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en']);
        $this->submissions = new FakeSubmissions();
        $this->notifier = new FakeNotifier();
        $this->service = new SubmissionRegistrationService(
            new DbRegistrationRepository($this->db),
            new DbEditionRepository($this->db),
            $this->submissions,
            $this->notifier,
            new AuditLogger($this->db),
        );
        $this->consent = (new ConsentText('Згода на обробку даних'))->version;
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');
    }

    private function edition(array $overrides = [], int $contextId = 1): int
    {
        $service = new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $service->create(new ContextInfo($contextId, 'uk', ['uk']), EditionTest::validInput($overrides + ['status' => 'cfp_open']));
        self::assertSame([], $errors->toArray());
        return (int) $e?->id;
    }

    /** @param array<string, mixed> $input */
    private function save(int $submissionId, array $input): array
    {
        return $this->service->save($this->ctx, new SubmissionInfo($submissionId, 1, false), $input, $this->consent, 1, $this->now);
    }

    private function complete(int $editionId, int $submissionId = 1): void
    {
        [$saved, $errors] = $this->save($submissionId, [
            'editionId' => (string) $editionId,
            'presentationType' => 'oral',
            'participationForm' => 'online',
            'presenterEmail' => 'Petro@Example.org',
            'consent' => ['true'],
        ]);
        self::assertNotNull($saved);
        self::assertTrue($errors->isEmpty());
    }

    public function testQuestionnaireIsSavedAndConsentRecorded(): void
    {
        $edition = $this->edition();
        $this->complete($edition);
        $r = $this->service->get(1);
        self::assertSame($edition, $r->editionId);
        self::assertSame('petro@example.org', $r->presenterEmail, 'emails are compared in lower case');
        self::assertSame($this->consent, $r->consentVersion);
        self::assertSame(1, $r->consentUserId);
        self::assertEquals($this->now, $r->consentAt);

        $this->save(1, ['consent' => false]);
        self::assertFalse($this->service->get(1)->hasConsent(), 'unticking removes the consent');
    }

    public function testClosedEditionIsNotAcceptedButOtherAnswersAreKept(): void
    {
        $closed = $this->edition(['path' => 'old', 'cfpOpensAt' => '2026-01-01', 'abstractDeadline' => '2026-02-01', 'paperDeadline' => '']);
        $foreign = $this->edition(['path' => 'other'], 2);
        foreach ([$closed, $foreign] as $id) {
            [$saved, $errors] = $this->save(1, ['editionId' => $id, 'presentationType' => 'poster']);
            self::assertTrue($errors->has('conferenceEditionId'));
            self::assertNull($saved?->editionId);
            self::assertSame('poster', $saved?->presentationType?->value);
        }
    }

    public function testSubmitIsBlockedWithoutQuestionnaireOrOutsideWindow(): void
    {
        self::assertTrue($this->service->validateSubmit($this->ctx, 1, $this->consent, $this->now)->isEmpty(), 'no open call: no step, no check');

        $edition = $this->edition();
        $errors = $this->service->validateSubmit($this->ctx, 1, $this->consent, $this->now);
        self::assertSame(['conferenceEditionId'], array_keys($errors->toArray()));
        self::assertTrue($this->service->validateSubmit($this->ctx, 1, $this->consent, $this->now, true)->isEmpty(), 'journal takes regular articles');

        $this->complete($edition);
        self::assertSame([], $this->service->validateSubmit($this->ctx, 1, $this->consent, $this->now)->toArray());

        $errors = $this->service->validateSubmit($this->ctx, 1, 'changed-text-ver', $this->now);
        self::assertSame(['conferenceConsent'], array_keys($errors->toArray()));

        $this->save(1, ['presenterEmail' => 'someone@else.org']);
        self::assertSame(['conferencePresenterEmail'], array_keys($this->service->validateSubmit($this->ctx, 1, $this->consent, $this->now)->toArray()));
        $this->save(1, ['presenterEmail' => 'petro@example.org']);

        $late = new DateTimeImmutable('2027-03-01T00:00:00+02:00');
        self::assertSame(['conferenceEditionId'], array_keys($this->service->validateSubmit($this->ctx, 1, $this->consent, $late)->toArray()), 'deadline passed (EDN-03)');
    }

    public function testAuthorsBecomeParticipantsAndCoauthorsGetOneTimeLinks(): void
    {
        $edition = $this->edition();
        $this->complete($edition);

        self::assertSame(1, $this->service->onSubmitted($this->ctx, 1, 1, $this->now));
        self::assertSame(0, $this->service->onSubmitted($this->ctx, 1, 1, $this->now), 'idempotent');

        $participants = $this->service->participantsOfSubmission(1);
        self::assertSame(
            [['petro@example.org', 'presenter', 'confirmed'], ['olena@example.org', 'coauthor', 'pending']],
            array_map(static fn ($p) => [$p['email'], $p['role'], $p['confirmationStatus']], $participants),
        );
        self::assertSame('online', $participants[0]['participationForm']);
        self::assertSame([1, $edition, 'oral'], $this->submissions->linked);
        self::assertNotNull($this->service->get(1)->registeredAt);
        self::assertSame('Олена', $this->db->table('conf_participant_settings')->where('setting_name', 'givenName')->where('locale', 'uk')->where('participant_id', $participants[1]['participantId'])->value('setting_value'));
        self::assertSame($this->consent, $this->db->table('conf_participants')->where('id', $participants[0]['participantId'])->value('consent_version'), 'consent of the submitter only');
        self::assertNull($this->db->table('conf_participants')->where('id', $participants[1]['participantId'])->value('consent_version'));

        $letter = $this->notifier->sent[0];
        self::assertSame('Olena@Example.org', $letter->email, 'the letter goes to the address as the author typed it');
        self::assertSame('Петро Іваненко', $letter->submitterName);
        self::assertSame('Конференція 2027', $letter->editionTitle['uk']);
        self::assertEquals($this->now->modify('+30 days'), $letter->expiresAt);
        self::assertSame(64, strlen((string) $this->db->table('conf_participants')->where('id', $participants[1]['participantId'])->value('confirmation_token_hash')), 'only the hash is stored');

        [$saved, $errors] = $this->save(1, ['presentationType' => 'poster']);
        self::assertNull($saved, 'no changes after registration');
        self::assertTrue($errors->has('conferenceEditionId'));

        self::assertSame('invalid', $this->service->inspectToken(2, $letter->token, $this->now)['result'], 'another journal');
        self::assertSame('pending', $this->service->inspectToken(1, $letter->token, $this->now)['result']);
        self::assertSame('confirmed', $this->service->answer(1, $letter->token, true, $this->now)['result']);
        self::assertSame('invalid', $this->service->answer(1, $letter->token, false, $this->now)['result'], 'one-time link');
        self::assertSame('confirmed', $this->service->participantsOfSubmission(1)[1]['confirmationStatus']);
    }

    public function testExpiredLinkAndSecondSubmissionOfSameAuthor(): void
    {
        $edition = $this->edition();
        $this->complete($edition);
        $this->service->onSubmitted($this->ctx, 1, 1, $this->now);
        $token = $this->notifier->sent[0]->token;
        self::assertSame('expired', $this->service->answer(1, $token, true, $this->now->modify('+31 days'))['result']);

        // The same co-author on another submission: one participant, two participations.
        $this->complete($edition, 2);
        $this->service->onSubmitted($this->ctx, 2, 1, $this->now);
        self::assertSame(2, $this->db->table('conf_participants')->count());
        self::assertSame(4, $this->db->table('conf_participations')->count());
        self::assertCount(2, $this->notifier->sent, 'a new link replaces the old one');
        self::assertSame('invalid', $this->service->inspectToken(1, $token, $this->now)['result']);
        self::assertSame('pending', $this->service->inspectToken(1, $this->notifier->sent[1]->token, $this->now)['result']);
    }

    public function testFailedLetterDoesNotBreakSubmission(): void
    {
        $edition = $this->edition();
        $this->complete($edition);
        $this->notifier->fail = true;
        self::assertSame(0, $this->service->onSubmitted($this->ctx, 1, 1, $this->now));
        self::assertNotNull($this->service->get(1)->registeredAt);
        self::assertSame('pending', $this->service->participantsOfSubmission(1)[1]['confirmationStatus']);
    }
}

final class FakeSubmissions implements SubmissionDirectory
{
    /** @var array<int, mixed> */
    public array $linked = [];

    public function find(int $submissionId): ?SubmissionInfo
    {
        return new SubmissionInfo($submissionId, 1, false, ['uk' => 'Тези ' . $submissionId], 'uk');
    }

    public function authors(int $submissionId): array
    {
        return [
            new AuthorInfo(1, 'petro@example.org', ['uk' => 'Петро'], ['uk' => 'Іваненко'], 'Університет', null, 'UA', 1),
            new AuthorInfo($submissionId === 1 ? 2 : 3, 'Olena@Example.org', ['uk' => 'Олена', 'en' => 'Olena'], ['uk' => 'Коваль']),
        ];
    }

    public function linkToEdition(int $submissionId, int $editionId, ?string $presentationType): void
    {
        $this->linked = [$submissionId, $editionId, $presentationType];
    }

    public function userEmail(int $userId): ?string
    {
        return $userId === 1 ? 'petro@example.org' : null;
    }

    public function userIdByEmail(string $email): ?int
    {
        return null;
    }
}

final class FakeNotifier implements ParticipantNotifier
{
    /** @var list<ConfirmationRequest> */
    public array $sent = [];
    public bool $fail = false;

    public function requestConfirmation(ContextInfo $context, ConfirmationRequest $request): void
    {
        if ($this->fail) {
            throw new \RuntimeException('mail server down');
        }
        $this->sent[] = $request;
    }
}
