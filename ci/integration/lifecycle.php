<?php

/**
 * Plugin lifecycle check inside a real OJS 3.5 installation.
 *
 * Usage (from the OJS root):
 *   php lifecycle.php install <package.tar.gz>
 *   php lifecycle.php upgrade <package.tar.gz>
 *   php lifecycle.php context <path> <primaryLocale>      create a journal if missing
 *   php lifecycle.php enable <contextPath>
 *   php lifecycle.php migrate-twice
 *   php lifecycle.php assert-tables <table>[,<table>…]
 *   php lifecycle.php assert-column <table> <column>
 *   php lifecycle.php steps
 *   php lifecycle.php user-groups <contextPath>             REG-01, idempotent
 *   php lifecycle.php publication-schema <contextPath>      plan 8.3 properties
 *   php lifecycle.php enable-site                           site level ("Upcoming events", EDN-08)
 *   php lifecycle.php run-windows-task                      EDN-03 scheduled task, once
 *   php lifecycle.php run-reminders-task                    COM-03 scheduled task, once
 *   php lifecycle.php next-numbers <contextPath> <year> <n> FEE-05: n invoice numbers, one transaction each
 *   php lifecycle.php clone-abstracts <contextPath> <submissionId> <n> <authorUserId>  DEC-03: n submitted copies of an abstract
 *   php lifecycle.php assign-author <contextPath> <submissionId> <userId>
 *   php lifecycle.php email-log <submissionId> <templateKey>
 *
 * install / upgrade call the same PluginHelper methods as the upload form
 * of the plugin grid. upgrade loads the plugin first, exactly like the
 * upgrade request does, to reproduce "old classes already loaded".
 */

declare(strict_types=1);

require getcwd() . '/tools/bootstrap.php';

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginHelper;
use PKP\plugins\PluginRegistry;

class ConferenceLifecycleTool extends \PKP\cliTool\CommandLineTool
{
    public function execute(): void
    {
        $cmd = $this->argv[0] ?? '';
        try {
            match ($cmd) {
                'install' => $this->install($this->argv[1]),
                'upgrade' => $this->upgrade($this->argv[1]),
                'context' => $this->context($this->argv[1], $this->argv[2] ?? 'uk'),
                'enable' => $this->enable($this->argv[1]),
                'migrate-twice' => $this->migrateTwice(),
                'assert-tables' => $this->assertTables(explode(',', $this->argv[1])),
                'assert-column' => $this->assertColumn($this->argv[1], $this->argv[2]),
                'steps' => $this->steps(),
                'user-groups' => $this->userGroups($this->argv[1]),
                'publication-schema' => $this->publicationSchema($this->argv[1]),
                'enable-site' => $this->enableSite(),
                'run-windows-task' => $this->runWindowsTask(),
                'run-reminders-task' => $this->runRemindersTask(),
                'author-group' => $this->authorGroup($this->argv[1]),
                'new-token' => $this->newToken($this->argv[1], $this->argv[2]),
                'new-user' => $this->newUser($this->argv[1], $this->argv[2], $this->argv[3], $this->argv[4] ?? 'reader'),
                'ids' => $this->ids($this->argv[1]),
                'theme' => $this->theme($this->argv[1], $this->argv[2]),
                'next-numbers' => $this->nextNumbers($this->argv[1], (int) $this->argv[2], (int) $this->argv[3]),
                'assign-author' => $this->assignAuthor($this->argv[1], (int) $this->argv[2], (int) $this->argv[3]),
                'email-log' => $this->emailLog((int) $this->argv[1], $this->argv[2]),
                'clone-abstracts' => $this->cloneAbstracts($this->argv[1], (int) $this->argv[2], (int) $this->argv[3], (int) $this->argv[4]),
                'publish-article' => $this->publishArticle((int) $this->argv[1], $this->argv[2], $this->argv[3] ?? null),
                'set-abstract' => $this->setAbstract((int) $this->argv[1], $this->argv[2], $this->argv[3], $this->argv[4] ?? ''),
                'review-form' => $this->reviewForm($this->argv[1], $this->argv[2]),
                'assign-reviewer' => $this->assignReviewer((int) $this->argv[1], (int) $this->argv[2]),
                'set-affiliation' => $this->setAffiliation((int) $this->argv[1], (int) $this->argv[2], $this->argv[3]),
                'seed-participants' => $this->seedParticipants($this->argv[1], (int) $this->argv[2], (int) $this->argv[3]),
                'add-listener' => $this->addListener($this->argv[1], (int) $this->argv[2], $this->argv[3], ($this->argv[4] ?? '') === '' ? null : $this->argv[4], $this->argv[5] ?? 'online'),
                'participant-id' => $this->participantId((int) $this->argv[1], $this->argv[2]),
                'review-of' => $this->reviewOf((int) $this->argv[1], $this->argv[2]),
                'plugin-enabled' => $this->pluginEnabled($this->argv[1], $this->argv[2], $this->argv[3] === '1'),
                'issue-data' => $this->issueData((int) $this->argv[1]),
                'gateway-payment' => $this->gatewayPayment($this->argv[1]),
                default => throw new RuntimeException("Unknown command {$cmd}"),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "FAIL {$cmd}: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            exit(1);
        }
    }

    private function copyToTemp(string $package): string
    {
        $tmp = sys_get_temp_dir() . '/' . uniqid('cm', true) . '.tar.gz';
        copy($package, $tmp) || throw new RuntimeException('cannot copy package');
        return $tmp;
    }

    private function install(string $package): void
    {
        $version = (new PluginHelper())->installPlugin($this->copyToTemp($package), basename($package));
        echo 'OK installed ', $version->getVersionString(false), "\n";
    }

    private function upgrade(string $package): void
    {
        // The real upgrade request has the old plugin registered already.
        $plugin = PluginRegistry::loadPlugin('generic', 'conferenceManager');
        $plugin?->getInstallMigration();
        $version = (new PluginHelper())->upgradePlugin('generic', 'conferenceManager', $this->copyToTemp($package), basename($package));
        echo 'OK upgraded to ', $version->getVersionString(false), "\n";
    }

    private function context(string $path, string $locale): void
    {
        $dao = Application::getContextDAO();
        if ($dao->getByPath($path)) {
            echo "OK context {$path} exists\n";
            return;
        }
        $context = $dao->newDataObject();
        $context->setData('urlPath', $path);
        $context->setData('name', ['en' => "Test conferences {$path}", 'uk' => "Тестові конференції {$path}"]);
        $context->setData('primaryLocale', $locale);
        $context->setData('supportedLocales', ['uk', 'en']);
        $context->setData('supportedFormLocales', ['uk', 'en']);
        $context->setData('supportedSubmissionLocales', ['uk', 'en']);
        $context->setData('contactEmail', "{$path}@example.org");
        $context->setData('contactName', 'Org committee');
        $context->setData('enabled', true);
        $request = Application::get()->getRequest();
        $context = app()->get('context')->add($context, $request);
        echo 'OK context ', $path, ' id=', $context->getId(), "\n";
    }

    private function enable(string $path): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $plugin = PluginRegistry::loadPlugin('generic', 'conferenceManager', $context->getId())
            ?? throw new RuntimeException('plugin not found');
        $plugin->updateSetting($context->getId(), 'enabled', true, 'bool');
        echo 'OK enabled in ', $path, "\n";
    }

    /**
     * DST-05: switches the journal to a theme from plugins/themes/{dir}
     * (default, defaultManuscript, healthSciences).
     */
    private function theme(string $path, string $dir): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $plugin = PluginRegistry::loadPlugin('themes', $dir, $context->getId()) ?? throw new RuntimeException('theme not found');
        $plugin->updateSetting($context->getId(), 'enabled', true, 'bool');
        $context->setData('themePluginPath', $dir);
        Application::getContextDAO()->updateObject($context);
        echo 'OK theme ', $dir, ' in ', $path, "\n";
    }

    private function migrateTwice(): void
    {
        $class = 'APP\\plugins\\generic\\conferenceManager\\classes\\Migrations\\ConferenceSchemaMigration';
        $before = DB::table('conf_schema_steps')->count();
        (new $class())->up();
        (new $class())->up();
        $after = DB::table('conf_schema_steps')->count();
        if ($before !== $after) {
            throw new RuntimeException("steps changed {$before} -> {$after}");
        }
        echo "OK migration up() twice, steps={$after}\n";
    }

    /** @param list<string> $tables */
    private function assertTables(array $tables): void
    {
        foreach ($tables as $t) {
            DB::getSchemaBuilder()->hasTable($t) || throw new RuntimeException("missing table {$t}");
        }
        echo 'OK tables: ', implode(', ', $tables), "\n";
    }

    private function assertColumn(string $table, string $column): void
    {
        DB::getSchemaBuilder()->hasColumn($table, $column) || throw new RuntimeException("missing column {$table}.{$column}");
        echo "OK column {$table}.{$column}\n";
    }

    private function userGroups(string $path): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $contextId = (int) $context->getId();
        $plugin = PluginRegistry::loadPlugin('generic', 'conferenceManager', $contextId)
            ?? throw new RuntimeException('plugin not found');
        $first = $plugin->ensureUserGroups($contextId);
        $second = $plugin->ensureUserGroups($contextId);
        count($first) === 2 || throw new RuntimeException('groups not created');
        $first === $second || throw new RuntimeException('ensure is not idempotent');
        foreach (['participant' => \PKP\security\Role::ROLE_ID_READER, 'registrationManager' => \PKP\security\Role::ROLE_ID_ASSISTANT] as $key => $role) {
            $n = DB::table('user_groups as g')
                ->join('user_group_settings as s', 's.user_group_id', '=', 'g.user_group_id')
                ->where('g.context_id', $contextId)->where('g.role_id', $role)
                ->where('s.setting_name', 'nameLocaleKey')
                ->where('s.setting_value', "plugins.generic.conferenceManager.userGroup.{$key}")
                ->count();
            $n === 1 || throw new RuntimeException("{$key}: {$n} groups");
        }
        $group = \APP\facades\Repo::userGroup()->get($first['participantUserGroupId'], $contextId);
        ($group && $group->permitSelfRegistration) || throw new RuntimeException('participant group must allow self-registration');
        echo 'OK user groups in ', $path, ': ', json_encode($first), ' ', json_encode($group->name, JSON_UNESCAPED_UNICODE), "\n";
    }

    private function publicationSchema(string $path): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        PluginRegistry::loadCategory('generic', true, (int) $context->getId());
        $schema = app()->get('schema')->get(\PKP\services\PKPSchemaService::SCHEMA_PUBLICATION, true);
        foreach (['conferenceEditionId' => 'integer', 'presentationType' => 'string', 'pageCount' => 'integer'] as $prop => $type) {
            ($schema->properties->{$prop}->type ?? null) === $type || throw new RuntimeException("publication.{$prop} missing");
        }
        // Stored and read back through the OJS repository.
        $publication = \APP\facades\Repo::publication()->newDataObject(['presentationType' => 'poster', 'pageCount' => 6]);
        in_array('in:oral,poster,online,absentee', (array) $schema->properties->presentationType->validation, true)
            || throw new RuntimeException('presentationType must be validated');
        echo 'OK publication schema: conferenceEditionId, presentationType (', $publication->getData('presentationType'), "), pageCount\n";
    }

    private function enableSite(): void
    {
        $plugin = PluginRegistry::loadPlugin('generic', 'conferenceManager')
            ?? throw new RuntimeException('plugin not found');
        $plugin->updateSetting(Application::SITE_CONTEXT_ID, 'enabled', true, 'bool');
        echo "OK enabled at site level\n";
    }

    private function runWindowsTask(): void
    {
        PluginRegistry::loadCategory('generic', true);
        $task = new \APP\plugins\generic\conferenceManager\classes\Tasks\EditionWindowsTask();
        $task->execute() || throw new RuntimeException('task failed');
        echo "OK edition windows task\n";
    }

    private function runRemindersTask(): void
    {
        PluginRegistry::loadCategory('generic', true);
        $task = new \APP\plugins\generic\conferenceManager\classes\Tasks\ReminderTask();
        $task->execute() || throw new RuntimeException('task failed');
        echo "OK reminders task\n";
    }

    /** Prints the id of the first author group of the context (for the API smoke test). */
    private function authorGroup(string $path): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $id = DB::table('user_groups')->where('context_id', $context->getId())
            ->where('role_id', \PKP\security\Role::ROLE_ID_AUTHOR)->orderBy('user_group_id')->value('user_group_id');
        $id || throw new RuntimeException('no author group');
        echo $id, "\n";
    }

    /**
     * A new confirmation link for a pending participant, for OJS builds
     * whose "log" mailer does not work (3.5.0-5), so the letter cannot be read.
     */
    private function newToken(string $path, string $email): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $id = DB::table('conf_participants')->where('context_id', $context->getId())->where('email', strtolower($email))
            ->where('confirmation_status', 'pending')->orderByDesc('id')->value('id') ?? throw new RuntimeException('no pending participant');
        [$token, $hash] = \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationToken::generate();
        (new \APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository(DB::connection()))
            ->setConfirmationToken((int) $id, $hash, new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC')));
        echo $path, '/conference/confirm/', $token, "\n";
    }

    /** A reader of the context with the password "reader12345" (REG-05, REG-06 checks). */
    private function newUser(string $path, string $username, string $email, string $role = 'reader'): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $user = \APP\facades\Repo::user()->newDataObject();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword(\PKP\security\Validation::encryptCredentials($username, 'reader12345'));
        $user->setGivenName('Тарас', 'uk');
        $user->setFamilyName('Шевченко', 'uk');
        $user->setCountry('UA');
        $user->setDateRegistered(\PKP\core\Core::getCurrentDate());
        $id = \APP\facades\Repo::user()->add($user);
        $group = DB::table('user_groups')->where('context_id', $context->getId())
            ->where('role_id', match ($role) {
                'manager' => \PKP\security\Role::ROLE_ID_MANAGER,
                'reviewer' => \PKP\security\Role::ROLE_ID_REVIEWER,
                default => \PKP\security\Role::ROLE_ID_READER,
            })
            ->orderBy('user_group_id')->value('user_group_id');
        \APP\facades\Repo::userGroup()->assignUserToGroup($id, (int) $group);
        echo $id, "\n";
    }

    /**
     * DST-02: ids of the plugin's records of a context, as JSON, for the
     * isolation check from another context.
     */
    private function ids(string $path): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $c = (int) $context->getId();
        $pluck = static fn ($q): array => array_map('intval', $q->orderBy('id')->limit(5)->pluck('id')->all());
        $editions = $pluck(DB::table('conf_editions')->where('context_id', $c)->select('id as id'));
        $participants = DB::table('conf_participants')->where('context_id', $c)->pluck('id')->all();
        echo json_encode([
            'editions' => $editions,
            'participations' => $pluck(DB::table('conf_participations')->whereIn('participant_id', $participants)->select('id')),
            'invoices' => $pluck(DB::table('conf_invoices')->where('context_id', $c)->select('id')),
            'proofs' => $pluck(DB::table('conf_payment_proofs as p')->join('conf_invoices as i', 'i.id', '=', 'p.invoice_id')->where('i.context_id', $c)->select('p.id as id')),
            'certificates' => $pluck(DB::table('conf_certificates')->where('context_id', $c)->select('id')),
            'campaigns' => $pluck(DB::table('conf_campaigns')->where('context_id', $c)->select('id')),
            'batches' => $pluck(DB::table('conf_decision_batches')->where('context_id', $c)->select('id')),
            'submissions' => array_map('intval', DB::table('submissions')->where('context_id', $c)->orderBy('submission_id')->limit(5)->pluck('submission_id')->all()),
        ]), "\n";
    }

    /**
     * n submitted abstracts like the given one (same edition, questionnaire,
     * section), each with its own author assigned to the given user, so
     * that a bulk decision sends n letters. Prints the new ids on one line.
     */
    private function cloneAbstracts(string $path, int $sourceId, int $n, int $authorUserId): void
    {
        PluginRegistry::loadCategory('generic', true);
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $source = \APP\facades\Repo::submission()->get($sourceId) ?? throw new RuntimeException('no submission');
        $sp = $source->getCurrentPublication();
        $reg = DB::table('conf_submission_registrations')->where('submission_id', $sourceId)->first() ?? throw new RuntimeException('no questionnaire');
        $authorGroup = (int) DB::table('user_groups')->where('context_id', $context->getId())
            ->where('role_id', \PKP\security\Role::ROLE_ID_AUTHOR)->orderBy('user_group_id')->value('user_group_id');
        $info = \APP\plugins\generic\conferenceManager\classes\Core\ContextGateway::fromContext($context);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $submission = \APP\facades\Repo::submission()->newDataObject([
                'contextId' => $context->getId(), 'locale' => 'uk', 'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
                'status' => \APP\submission\Submission::STATUS_QUEUED, 'submissionProgress' => '',
                'dateSubmitted' => \PKP\core\Core::getCurrentDate(),
            ]);
            $publication = \APP\facades\Repo::publication()->newDataObject([
                'title' => ['uk' => "Тези для масового рішення {$i}"], 'abstract' => ['uk' => '<p>Зміст.</p>'],
                'sectionId' => $sp->getData('sectionId'), 'locale' => 'uk',
                'conferenceEditionId' => (int) $reg->edition_id, 'presentationType' => $reg->presentation_type,
            ]);
            $id = \APP\facades\Repo::submission()->add($submission, $publication, $context);
            $pubId = (int) \APP\facades\Repo::submission()->get($id)->getData('currentPublicationId');
            $email = "bulk{$id}@example.org";
            $authorId = \APP\facades\Repo::author()->add(\APP\facades\Repo::author()->newDataObject([
                'publicationId' => $pubId, 'email' => $email, 'givenName' => ['uk' => 'Автор'], 'familyName' => ['uk' => "Номер{$i}"],
                'country' => 'UA', 'userGroupId' => $authorGroup, 'includeInBrowse' => true, 'seq' => 0,
            ]));
            \APP\facades\Repo::publication()->edit(\APP\facades\Repo::publication()->get($pubId), ['primaryContactId' => $authorId]);
            \APP\facades\Repo::stageAssignment()->build($id, $authorGroup, $authorUserId);
            DB::table('conf_submission_registrations')->insert([
                'submission_id' => $id, 'edition_id' => $reg->edition_id, 'presentation_type' => $reg->presentation_type,
                'participation_form' => $reg->participation_form, 'presenter_email' => $email, 'consent_version' => $reg->consent_version,
                'consent_at' => $reg->consent_at, 'consent_user_id' => $reg->consent_user_id, 'category_id' => $reg->category_id,
                'registered_at' => null,
            ]);
            \APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory::registrations()->onSubmitted($info, $id, 1, $now);
            $ids[] = $id;
        }
        echo implode(',', $ids), "\n";
    }

    /**
     * The user becomes the assigned author of the submission: decision
     * letters go to assigned authors. Not the editor: OJS lets an editor
     * who is also the assigned author decide nothing.
     */
    private function assignAuthor(string $path, int $submissionId, int $userId): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $group = (int) DB::table('user_groups')->where('context_id', $context->getId())
            ->where('role_id', \PKP\security\Role::ROLE_ID_AUTHOR)->orderBy('user_group_id')->value('user_group_id');
        \APP\facades\Repo::stageAssignment()->build($submissionId, $group, $userId);
        echo "OK\n";
    }

    /**
     * How many letters of this template the submission's email log has,
     * matched by the start of the default Ukrainian subject.
     */
    private function emailLog(int $submissionId, string $key): void
    {
        $subject = (string) DB::table('email_templates_default_data')->where('email_key', $key)->where('locale', 'uk')->value('subject');
        $subject !== '' || throw new RuntimeException("no default template {$key}");
        $prefix = trim(explode('{', $subject)[0]);
        echo DB::table('email_log')->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)->where('assoc_id', $submissionId)
            ->where('subject', 'like', $prefix . '%')->count(), "\n";
    }

    /** Many listeners in an edition, for the speed check of the register (REG-07). */
    private function seedParticipants(string $path, int $editionId, int $count): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 1; $i <= $count; $i++) {
            $pid = DB::table('conf_participants')->insertGetId([
                'context_id' => $context->getId(), 'edition_id' => $editionId, 'email' => "seed{$i}@example.org",
                'affiliation' => 'Університет ' . ($i % 37), 'confirmation_status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('conf_participant_settings')->insert([
                ['participant_id' => $pid, 'locale' => 'uk', 'setting_name' => 'givenName', 'setting_value' => 'Учасник'],
                ['participant_id' => $pid, 'locale' => 'uk', 'setting_name' => 'familyName', 'setting_value' => 'Номер' . $i],
            ]);
            DB::table('conf_participations')->insert(['participant_id' => $pid, 'role' => 'listener', 'participation_form' => 'online', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        echo "OK seeded {$count}\n";
    }

    /** A confirmed listener of an edition, linked to an OJS account when a username is given; prints "participantId participationId". */
    private function addListener(string $path, int $editionId, string $email, ?string $username, string $form = 'online'): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $now = gmdate('Y-m-d H:i:s');
        $userId = $username === null ? null : DB::table('users')->where('username', $username)->value('user_id');
        $pid = DB::table('conf_participants')->insertGetId([
            'context_id' => $context->getId(), 'edition_id' => $editionId, 'user_id' => $userId, 'email' => $email,
            'affiliation' => 'Коледж', 'confirmation_status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('conf_participant_settings')->insert([
            ['participant_id' => $pid, 'locale' => 'uk', 'setting_name' => 'givenName', 'setting_value' => 'Ірина'],
            ['participant_id' => $pid, 'locale' => 'uk', 'setting_name' => 'familyName', 'setting_value' => 'Слухач ' . strtok($email, '@')],
        ]);
        $id = DB::table('conf_participations')->insertGetId(['participant_id' => $pid, 'role' => 'listener', 'participation_form' => $form, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        echo "{$pid} {$id}\n";
    }

    /** Prints "participantId participationId" of an e-mail address in an edition (0 0 when none). */
    private function participantId(int $editionId, string $email): void
    {
        $pid = (int) DB::table('conf_participants')->where('edition_id', $editionId)->where('email', $email)->value('id');
        echo $pid, ' ', (int) DB::table('conf_participations')->where('participant_id', $pid)->min('id'), "\n";
    }

    /**
     * Takes n numbers of the invoice counter, each in its own transaction
     * with a short pause under the lock, so parallel runs interleave.
     */
    private function nextNumbers(string $path, int $year, int $n): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $repo = new \APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository(DB::connection());
        $repo->ensureCounter((int) $context->getId(), $year);
        for ($i = 0; $i < $n; $i++) {
            echo DB::transaction(static function () use ($repo, $context, $year): int {
                $next = $repo->nextNumber((int) $context->getId(), $year);
                usleep(random_int(0, 3000));
                return $next;
            }), "\n";
        }
    }

    /**
     * Marks the current publication of a submission as published (tests
     * of pages that need a public article: the smoke issue stays
     * unpublished) and, if given, sets its title in the primary locale.
     */
    private function publishArticle(int $submissionId, string $date, ?string $title): void
    {
        $pub = (int) DB::table('submissions')->where('submission_id', $submissionId)->value('current_publication_id');
        $pub > 0 || throw new RuntimeException('no publication');
        DB::table('publications')->where('publication_id', $pub)->update(['status' => \APP\submission\Submission::STATUS_PUBLISHED, 'date_published' => $date]);
        if ($title !== null) {
            DB::table('publication_settings')->where('publication_id', $pub)->where('setting_name', 'title')->update(['setting_value' => $title]);
        }
        echo "OK published {$submissionId}\n";
    }

    /** DEC-07: an active review form of a journal; prints its id. */
    private function reviewForm(string $path, string $title): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $id = DB::table('review_forms')->insertGetId(['assoc_type' => Application::getContextAssocType(), 'assoc_id' => $context->getId(), 'seq' => 1, 'is_active' => 1], 'review_form_id');
        DB::table('review_form_settings')->insert(['review_form_id' => $id, 'locale' => 'en', 'setting_name' => 'title', 'setting_value' => $title, 'setting_type' => 'string']);
        echo $id, "\n";
    }

    /**
     * DEC-07: a new reviewer (a reader of the journal) in the last external
     * review round of a submission, through the OJS repository, so the
     * plugin hook runs; prints the review form the assignment got.
     */
    private function assignReviewer(int $submissionId, int $n): void
    {
        $submission = \APP\facades\Repo::submission()->get($submissionId) ?? throw new RuntimeException('no submission');
        $round = DB::table('review_rounds')->where('submission_id', $submissionId)->orderByDesc('round')->first() ?? throw new RuntimeException('no round');
        $context = Application::getContextDAO()->getById((int) $submission->getData('contextId'));
        ob_start();
        $this->newUser($context->getPath(), 'cmreviewer' . $n . '_' . $submissionId, 'cmreviewer' . $n . '_' . $submissionId . '@example.org');
        $userId = (int) trim((string) ob_get_clean());
        $a = \APP\facades\Repo::reviewAssignment()->newDataObject([
            'submissionId' => $submissionId, 'reviewerId' => $userId, 'reviewRoundId' => (int) $round->review_round_id,
            'stageId' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, 'round' => (int) $round->round, 'reviewMethod' => 2,
            'dateAssigned' => \PKP\core\Core::getCurrentDate(), 'dateNotified' => \PKP\core\Core::getCurrentDate(),
            'dateDue' => '2030-01-01', 'dateResponseDue' => '2030-01-01',
        ]);
        $id = \APP\facades\Repo::reviewAssignment()->add($a);
        echo 'round=', $round->round, ' form=', (int) DB::table('review_assignments')->where('review_id', $id)->value('review_form_id'), "\n";
    }

    /** EDN-10: turns a generic plugin (by its folder) on or off in a context. */
    private function pluginEnabled(string $path, string $folder, bool $on): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $plugin = \PKP\plugins\PluginRegistry::loadPlugin('generic', $folder, (int) $context->getId()) ?? throw new RuntimeException('no plugin ' . $folder);
        $plugin->updateSetting((int) $context->getId(), 'enabled', $on, 'bool');
        echo $plugin->getName(), ' ', $on ? 'on' : 'off', "\n";
    }

    /** EDN-10: the TIB Conference fields of an issue, as JSON. */
    private function issueData(int $issueId): void
    {
        $out = [];
        foreach (DB::table('issue_settings')->where('issue_id', $issueId)->where('setting_name', 'like', 'conference%')->get() as $row) {
            $out[$row->setting_name] = (string) $row->setting_value;
        }
        ksort($out);
        echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
    }

    /** DEC-10: the review assignment of a reviewer (by email) in the last round, with its files and dates. */
    private function reviewOf(int $submissionId, string $email): void
    {
        $userId = (int) DB::table('users')->where('email', $email)->value('user_id');
        $a = DB::table('review_assignments')->where('submission_id', $submissionId)->where('reviewer_id', $userId)->orderByDesc('review_id')->first();
        if ($a === null) {
            echo "none\n";
            return;
        }
        $files = DB::table('review_files')->where('review_id', $a->review_id)->count();
        $round = DB::table('review_round_files')->where('review_round_id', $a->review_round_id)->count();
        $note = DB::table('notifications')->where('user_id', $userId)->where('assoc_id', $a->review_id)->count();
        echo 'round=', $a->round, ' files=', $files, '/', $round, ' due=', $a->date_due === null ? 'no' : 'yes', ' notified=', $note, "\n";
    }

    /** DEC-08: the affiliation of a user and of the first author of a submission. */
    private function setAffiliation(int $submissionId, int $userId, string $text): void
    {
        $user = \APP\facades\Repo::user()->get($userId) ?? throw new RuntimeException('no user');
        \APP\facades\Repo::user()->edit($user, ['affiliation' => ['en' => $text, 'uk' => $text]]);
        $publication = \APP\facades\Repo::submission()->get($submissionId)?->getCurrentPublication() ?? throw new RuntimeException('no publication');
        $authorId = (int) DB::table('authors')->where('publication_id', $publication->getId())->orderBy('seq')->value('author_id');
        $aff = \APP\facades\Repo::affiliation()->newDataObject(['authorId' => $authorId, 'name' => ['uk' => 'Кафедра права, ' . $text, 'en' => $text]]);
        \APP\facades\Repo::affiliation()->add($aff);
        echo "OK affiliation\n";
    }

    /** The abstract and keywords of the current publication, through the OJS repository. */
    private function setAbstract(int $submissionId, string $locale, string $abstract, string $keywords): void
    {
        $publication = \APP\facades\Repo::submission()->get($submissionId)?->getCurrentPublication() ?? throw new RuntimeException('no publication');
        $words = array_values(array_filter(array_map('trim', explode(',', $keywords))));
        \APP\facades\Repo::publication()->edit($publication, ['abstract' => [$locale => $abstract], 'keywords' => [$locale => $words]]);
        echo "OK abstract {$submissionId}\n";
    }

    private function steps(): void
    {
        foreach (DB::table('conf_schema_steps')->orderBy('step')->get() as $r) {
            echo $r->step, ' ', $r->applied_at, "\n";
        }
    }

    /** PAY-09: a card-payment attempt and what its invoice got, as JSON. */
    private function gatewayPayment(string $orderId): void
    {
        $p = DB::table('conf_gateway_payments')->where('order_id', $orderId)->first();
        $p !== null || throw new RuntimeException("no attempt {$orderId}");
        $paid = DB::table('conf_invoices')->where('id', $p->invoice_id)->value('paid_minor');
        echo json_encode(['status' => $p->status, 'details' => (string) $p->details, 'paidMinor' => (int) $paid]), "\n";
    }

}

(new ConferenceLifecycleTool($argv ?? []))->execute();
