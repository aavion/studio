<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Core\ActionLog\ActionLog;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Setup\DatabaseDriver;
use App\Setup\DatabaseUrlFactory;
use App\Setup\SetupCommandExecutorInterface;
use App\Setup\SetupCommandResult;
use App\Setup\SetupInput;
use App\Setup\SetupLanguageCatalog;
use App\Setup\SetupRunner;
use App\Security\UserFlowConfig;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class SetupRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio-setup-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/bin', 0777, true);
        mkdir($this->root.'/translations/runtime', 0777, true);
        mkdir($this->root.'/var', 0777, true);
        touch($this->root.'/bin/console');
        file_put_contents($this->root.'/translations/runtime/messages.en.yaml', "message: []\n");
        file_put_contents($this->root.'/translations/runtime/messages.de.yaml', "message: []\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItRunsSetupAndSeedsConfigurationAndAdmin(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'de',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$databasePath,
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-secret',
        ));

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ActionLog::class, $result->value());
        self::assertFalse($result->context()['halt_on_error']);
        self::assertFileExists($this->root.'/.env.test.local');
        self::assertStringContainsString("APP_SECRET='test-secret'", (string) file_get_contents($this->root.'/.env.test.local'));
        self::assertFileExists($this->root.'/.env.local.php');
        $dumpedEnvironment = include $this->root.'/.env.local.php';
        self::assertSame('1', $dumpedEnvironment['APP_SETUP_COMPLETED']);
        self::assertSame([
            ['composer', '--version'],
            ['composer', 'dump-env', 'test'],
            [PHP_BINARY, $this->root.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'cache:clear', '--env=test'],
        ], $executor->commands);

        $pdo = new PDO('sqlite:'.$databasePath);
        $title = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'site.title'")->fetchColumn();
        $url = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'site.url'")->fetchColumn();
        $language = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'localization.default_language'")->fetchColumn();
        $homePath = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'content.home_path'")->fetchColumn();
        $defaultAclGroup = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'user.default_acl_group'")->fetchColumn();
        $userMenuEnabled = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'user.menu.enabled'")->fetchColumn();
        $userMenuSortOrder = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'user.menu.sort_order'")->fetchColumn();
        $registrationMode = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'user.registration.mode'")->fetchColumn();
        $auditEnabled = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'security.audit.enabled'")->fetchColumn();
        $auditEvents = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'security.audit.events'")->fetchColumn();
        $statisticsEnabled = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'statistics.enabled'")->fetchColumn();
        $statisticsDnt = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'statistics.respect_do_not_track'")->fetchColumn();
        $aclGroups = $pdo->query('SELECT identifier, access_level, locked, allow_empty FROM acl_group ORDER BY access_level')->fetchAll(PDO::FETCH_ASSOC);
        $passwordHash = $pdo->query("SELECT password_hash FROM user_account WHERE username = 'admin'")->fetchColumn();
        $stateMarkers = $pdo->query("SELECT marker_key, marker_value FROM state_marker WHERE subject_type = 'user_account' ORDER BY marker_key")->fetchAll(PDO::FETCH_KEY_PAIR);
        $groups = $pdo->query("SELECT g.identifier FROM acl_group g INNER JOIN user_acl_group ug ON ug.group_uid = g.uid INNER JOIN user_account u ON u.uid = ug.user_uid WHERE u.username = 'admin' ORDER BY g.access_level")->fetchAll(PDO::FETCH_COLUMN);
        $home = $pdo->query("SELECT ci.slug, ci.status, ci.visibility, ci.active_revision_uid, cs.identifier AS schema_identifier FROM content_item ci INNER JOIN content_schema cs ON cs.uid = ci.schema_uid WHERE ci.slug = 'home'")->fetch(PDO::FETCH_ASSOC);
        $homeTitle = $pdo->query("SELECT field_content FROM content_field_value WHERE revision_uid = '20000000-0000-0000-0000-000000000101' AND field_identifier = 'title' AND language = 'en'")->fetchColumn();

        self::assertSame('Example Studio', json_decode((string) $title, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('https://example.test', json_decode((string) $url, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('de', json_decode((string) $language, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('/home', json_decode((string) $homePath, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('registered', json_decode((string) $defaultAclGroup, true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue(json_decode((string) $userMenuEnabled, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(900, json_decode((string) $userMenuSortOrder, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(UserFlowConfig::REGISTRATION_DISABLED, json_decode((string) $registrationMode, true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue(json_decode((string) $auditEnabled, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(ConfigAuditLogPolicy::DEFAULT_CATEGORIES, json_decode((string) $auditEvents, true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue(json_decode((string) $statisticsEnabled, true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue(json_decode((string) $statisticsDnt, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([
            ['identifier' => 'registered', 'access_level' => 1, 'locked' => 1, 'allow_empty' => 1],
            ['identifier' => 'editor', 'access_level' => 3, 'locked' => 0, 'allow_empty' => 1],
            ['identifier' => 'manager', 'access_level' => 6, 'locked' => 0, 'allow_empty' => 1],
            ['identifier' => 'admin', 'access_level' => 9, 'locked' => 1, 'allow_empty' => 0],
        ], array_map(static fn (array $row): array => [
            'identifier' => $row['identifier'],
            'access_level' => (int) $row['access_level'],
            'locked' => (int) $row['locked'],
            'allow_empty' => (int) $row['allow_empty'],
        ], $aclGroups));
        self::assertIsString($passwordHash);
        self::assertTrue(password_verify('secret-password', $passwordHash));
        self::assertSame([
            'created' => null,
            'password_changed' => null,
            'status_changed' => 'active',
        ], $stateMarkers);
        self::assertSame(['admin'], $groups);
        self::assertSame([
            'slug' => 'home',
            'status' => 'published',
            'visibility' => 'public',
            'active_revision_uid' => '20000000-0000-0000-0000-000000000101',
            'schema_identifier' => 'static_page',
        ], $home);
        self::assertSame('Example Studio', json_decode((string) $homeTitle, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testItRejectsShortAdminPasswordBeforeSetupSteps(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$databasePath,
            adminUsername: 'admin',
            adminPassword: 'short',
            adminEmail: 'admin@example.test',
            appSecret: 'test-secret',
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('setup.admin_password.too_short', $result->firstIssue()?->code());
        self::assertSame([], $executor->commands);
    }

    public function testItSeedsTheSameSqliteDatabaseThatSymfonyMigratesWhenUrlUsesKernelEnvironmentPlaceholder(): void
    {
        $databasePath = $this->root.'/var/data_dev.db';
        $this->createSchema($databasePath);
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), new RecordingSetupCommandExecutor());

        $result = $runner->run(new SetupInput(
            appEnv: 'dev',
            language: 'en',
            siteTitle: 'Placeholder Studio',
            defaultUri: 'https://placeholder.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db',
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-secret',
        ));

        self::assertTrue($result->isSuccess());
        self::assertFileDoesNotExist($this->root.'/var/data_%kernel.environment%.db');

        $pdo = new PDO('sqlite:'.$databasePath);
        self::assertSame(
            'Placeholder Studio',
            json_decode((string) $pdo->query("SELECT value FROM config_entry WHERE config_key = 'site.title'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM acl_group WHERE identifier = 'admin'")->fetchColumn());
    }

    public function testItStopsWhenDefaultSettingsCannotBeWritten(): void
    {
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), new RecordingSetupCommandExecutor());

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Broken Studio',
            defaultUri: 'https://broken.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$this->root.'/var/missing-schema.db',
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-secret',
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('seed_default_settings', $result->context()['failed_step']);
        self::assertSame(
            'config.write_failed',
            $result->context()['action_log']['entries'][4]['issues'][0]['code'],
        );
    }

    public function testItPreservesExistingAclGroupPrimaryKeysWhenSetupIsRerun(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $existingAdminGroupUid = '99999999-0000-0000-0000-000000000105';
        $existingAdminUserUid = '99999999-0000-0000-0000-000000000201';
        $pdo->prepare('INSERT INTO acl_group (uid, identifier, name, access_level, locked, allow_empty, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$existingAdminGroupUid, 'admin', '{"en":"Legacy Admin"}', 8, 0, 1, '{}']);
        $pdo->prepare('INSERT INTO user_account (uid, username, email, password_hash, profile, settings, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$existingAdminUserUid, 'legacy-admin', 'legacy-admin@example.test', password_hash('legacy-secret', PASSWORD_DEFAULT), '{}', '{}', 'active']);
        $pdo->prepare('INSERT INTO user_acl_group (user_uid, group_uid) VALUES (?, ?)')
            ->execute([$existingAdminUserUid, $existingAdminGroupUid]);

        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), new RecordingSetupCommandExecutor());

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$databasePath,
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-secret',
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame($existingAdminGroupUid, $pdo->query("SELECT uid FROM acl_group WHERE identifier = 'admin'")->fetchColumn());
        self::assertSame(9, (int) $pdo->query("SELECT access_level FROM acl_group WHERE identifier = 'admin'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT locked FROM acl_group WHERE identifier = 'admin'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT allow_empty FROM acl_group WHERE identifier = 'admin'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM user_acl_group WHERE user_uid = '$existingAdminUserUid' AND group_uid = '$existingAdminGroupUid'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM state_marker WHERE subject_type = 'acl_group' AND subject_uid = '$existingAdminGroupUid'")->fetchColumn());
    }

    public function testItStopsOnCommandFailureAndReturnsActionLogContext(): void
    {
        $executor = new RecordingSetupCommandExecutor(failureAt: 2, failure: new SetupCommandResult(1, '', 'dump-env failed'));
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$this->root.'/var/setup.db',
            appSecret: 'test-secret',
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('dump_environment', $result->context()['failed_step']);
        self::assertArrayHasKey('action_log', $result->context());
    }

    public function testItDoesNotLockSetupWhenFinalCacheClearFails(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor(failureAt: 4, failure: new SetupCommandResult(1, '', 'cache clear failed'));
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$databasePath,
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-secret',
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('clear_cache', $result->context()['failed_step']);
        self::assertFileExists($this->root.'/.env.local.php');
        $dumpedEnvironment = include $this->root.'/.env.local.php';
        self::assertArrayNotHasKey('APP_SETUP_COMPLETED', $dumpedEnvironment);
    }

    public function testItStopsWhenEnvironmentOverridesCannotBeWritten(): void
    {
        mkdir($this->root.'/.env.test.local');
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$this->root.'/var/setup.db',
            appSecret: 'test-secret',
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('write_environment', $result->context()['failed_step']);
        self::assertSame([], $executor->commands);
        self::assertSame(
            'message.setup.environment_file_write_failed',
            $result->context()['action_log']['entries'][1]['issues'][0]['translation_key'],
        );
    }

    public function testItUsesBundledComposerWhenSystemComposerIsUnavailable(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        touch($this->root.'/bin/composer');
        $executor = new RecordingSetupCommandExecutor(failureAt: 1, failure: new SetupCommandResult(1));
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$databasePath,
            appSecret: 'test-secret',
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([
            ['composer', '--version'],
            [PHP_BINARY, $this->root.'/bin/composer', '--version'],
            [PHP_BINARY, $this->root.'/bin/composer', 'dump-env', 'test'],
            [PHP_BINARY, $this->root.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'cache:clear', '--env=test'],
        ], $executor->commands);
    }

    public function testDryRunReturnsPlannedChangesWithoutWriting(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'de',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///'.$databasePath,
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
            dryRun: true,
        ));

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->context()['dry_run']);
        self::assertSame([], $executor->commands);
        self::assertFileDoesNotExist($this->root.'/.env.test.local');

        $pdo = new PDO('sqlite:'.$databasePath);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM config_entry')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_account')->fetchColumn());

        $log = $result->value();
        self::assertInstanceOf(ActionLog::class, $log);
        $entries = $log->toArray()['entries'];
        self::assertSame('success', $entries[0]['status']);
        self::assertSame('skipped', $entries[1]['status']);
        self::assertSame('de', $entries[4]['context']['settings']['localization.default_language']);
        self::assertSame('/home', $entries[4]['context']['settings']['content.home_path']);
        self::assertSame('registered', $entries[4]['context']['settings']['user.default_acl_group']);
        self::assertTrue($entries[4]['context']['settings']['user.menu.enabled']);
        self::assertSame(900, $entries[4]['context']['settings']['user.menu.sort_order']);
        self::assertSame(UserFlowConfig::REGISTRATION_DISABLED, $entries[4]['context']['settings'][UserFlowConfig::REGISTRATION_MODE_KEY]);
        self::assertTrue($entries[4]['context']['settings'][ConfigAuditLogPolicy::ENABLED_KEY]);
        self::assertSame(ConfigAuditLogPolicy::DEFAULT_CATEGORIES, $entries[4]['context']['settings'][ConfigAuditLogPolicy::EVENTS_KEY]);
        self::assertTrue($entries[4]['context']['settings'][AccessStatisticsPolicy::ENABLED_KEY]);
        self::assertTrue($entries[4]['context']['settings'][AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY]);
        self::assertSame('seed_initial_content', $entries[6]['name']);
        self::assertSame('/home', $entries[6]['context']['path']);
        self::assertSame('clear_cache', $entries[7]['name']);
        self::assertSame([PHP_BINARY, $this->root.'/bin/console', 'cache:clear', '--env=test'], $entries[7]['context']['command']);
        self::assertSame('mark_setup_completed', $entries[8]['name']);
    }

    public function testDryRunMasksDatabasePasswordsInActionLogContext(): void
    {
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'de',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::MySql,
            databaseHost: '127.0.0.1',
            databasePort: 3306,
            databaseName: 'aa_2',
            databaseUser: 'admin',
            databasePassword: 'bla',
            dryRun: true,
        ));

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ActionLog::class, $result->value());

        $entries = $result->value()->toArray()['entries'];
        self::assertSame('mysql://admin:[hidden]@127.0.0.1:3306/aa_2', $entries[1]['context']['would_write']['DATABASE_URL']);
    }

    public function testDryRunRejectsUnsupportedDatabaseUrlBeforeWriting(): void
    {
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'oracle://example',
            dryRun: true,
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('prepare_setup', $result->context()['failed_step']);
        self::assertSame([], $executor->commands);
        self::assertFileDoesNotExist($this->root.'/.env.test.local');
    }

    public function testDryRunRejectsUnsupportedSqliteUrlVariantsBeforeWriting(): void
    {
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:/tmp/studio.db',
            dryRun: true,
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('prepare_setup', $result->context()['failed_step']);
        self::assertSame([], $executor->commands);
        self::assertFileDoesNotExist($this->root.'/.env.test.local');
        self::assertSame('message.setup.step_failed', $result->issues()[0]->translationKey());
        self::assertSame(
            'SQLite database URLs must use the sqlite:///path/to/database.db format.',
            $result->issues()[0]->parameters()['%message%'],
        );
    }

    public function testLanguageCatalogDiscoversTranslationCatalogues(): void
    {
        $catalog = new SetupLanguageCatalog();

        self::assertSame(['de', 'en'], $catalog->availableLanguages($this->root));
        self::assertSame('en', $catalog->defaultLanguage($this->root));
        self::assertTrue($catalog->supports($this->root, 'de'));
        self::assertFalse($catalog->supports($this->root, 'fr'));
    }

    public function testItBuildsServerDatabaseUrlsFromConnectionParts(): void
    {
        $factory = new DatabaseUrlFactory();

        $url = $factory->create(new SetupInput(
            appEnv: 'prod',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::MySql,
            databaseHost: 'db.example.test',
            databasePort: 3307,
            databaseName: 'studio db',
            databaseUser: 'studio user',
            databasePassword: 'secret/pass',
        ), $this->root);

        self::assertSame('mysql://studio%20user:secret%2Fpass@db.example.test:3307/studio%20db', $url);
    }

    private function createSchema(string $databasePath): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $pdo->exec('CREATE TABLE acl_group (uid VARCHAR(36) NOT NULL PRIMARY KEY, identifier VARCHAR(80) NOT NULL UNIQUE, name CLOB NOT NULL, access_level INTEGER NOT NULL, locked BOOLEAN NOT NULL, allow_empty BOOLEAN NOT NULL, metadata CLOB NOT NULL)');
        $pdo->exec('CREATE TABLE state_marker (uid VARCHAR(36) NOT NULL PRIMARY KEY, subject_type VARCHAR(80) NOT NULL, subject_uid VARCHAR(36) NOT NULL, marker_key VARCHAR(80) NOT NULL, marker_at DATETIME NOT NULL, marker_by VARCHAR(180) DEFAULT NULL, marker_value VARCHAR(255) DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(subject_type, subject_uid, marker_key))');
        $pdo->exec('CREATE TABLE user_account (uid VARCHAR(36) NOT NULL PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, email VARCHAR(180) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, profile CLOB NOT NULL, settings CLOB NOT NULL, status VARCHAR(32) NOT NULL)');
        $pdo->exec('CREATE TABLE user_acl_group (user_uid VARCHAR(36) NOT NULL, group_uid VARCHAR(36) NOT NULL, PRIMARY KEY(user_uid, group_uid))');
        $pdo->exec('CREATE TABLE content_schema (uid VARCHAR(36) NOT NULL PRIMARY KEY, identifier VARCHAR(120) NOT NULL UNIQUE, source VARCHAR(255) NOT NULL, locked BOOLEAN NOT NULL, active_version_uid VARCHAR(36) DEFAULT NULL, labels CLOB NOT NULL, descriptions CLOB NOT NULL, metadata CLOB NOT NULL)');
        $pdo->exec('CREATE TABLE content_schema_version (uid VARCHAR(36) NOT NULL PRIMARY KEY, schema_uid VARCHAR(36) NOT NULL, version INTEGER NOT NULL, title CLOB NOT NULL, description CLOB NOT NULL, definition CLOB NOT NULL, custom_twig CLOB DEFAULT NULL, definition_hash VARCHAR(64) NOT NULL, use_min_level INTEGER DEFAULT NULL, use_group_identifiers CLOB DEFAULT NULL, edit_min_level INTEGER DEFAULT NULL, edit_group_identifiers CLOB DEFAULT NULL, manage_min_level INTEGER DEFAULT NULL, manage_group_identifiers CLOB DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(schema_uid, version))');
        $pdo->exec('CREATE TABLE content_item (uid VARCHAR(36) NOT NULL PRIMARY KEY, slug VARCHAR(160) NOT NULL, status VARCHAR(255) NOT NULL, parent_uid VARCHAR(36) NOT NULL DEFAULT \'/\', sort_order INTEGER NOT NULL, custom_url VARCHAR(1024) DEFAULT NULL UNIQUE, redirect_target VARCHAR(1024) DEFAULT NULL, schema_uid VARCHAR(36) DEFAULT NULL, schema_version INTEGER DEFAULT NULL, active_revision_uid VARCHAR(36) DEFAULT NULL, version INTEGER NOT NULL, available_languages CLOB NOT NULL, available_variants CLOB NOT NULL, visibility VARCHAR(255) NOT NULL, acl_restrictions CLOB NOT NULL, view_min_level INTEGER DEFAULT NULL, view_group_identifiers CLOB DEFAULT NULL, edit_min_level INTEGER DEFAULT NULL, edit_group_identifiers CLOB DEFAULT NULL, manage_min_level INTEGER DEFAULT NULL, manage_group_identifiers CLOB DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(parent_uid, slug))');
        $pdo->exec('CREATE TABLE content_revision (uid VARCHAR(36) NOT NULL PRIMARY KEY, content_uid VARCHAR(36) NOT NULL, version INTEGER NOT NULL, schema_uid VARCHAR(36) NOT NULL, schema_version_uid VARCHAR(36) NOT NULL, change_summary VARCHAR(255) DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(content_uid, version))');
        $pdo->exec('CREATE TABLE content_field_value (uid VARCHAR(36) NOT NULL PRIMARY KEY, revision_uid VARCHAR(36) NOT NULL, language VARCHAR(16) NOT NULL, variant VARCHAR(80) NOT NULL, field_identifier VARCHAR(160) NOT NULL, field_content CLOB NOT NULL, UNIQUE(revision_uid, language, variant, field_identifier))');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}

final class RecordingSetupCommandExecutor implements SetupCommandExecutorInterface
{
    /**
     * @var list<list<string>>
     */
    public array $commands = [];

    public function __construct(
        private readonly ?int $failureAt = null,
        private readonly ?SetupCommandResult $failure = null,
    )
    {
    }

    public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
    {
        $this->commands[] = $command;

        if (null !== $this->failure && $this->failureAt === count($this->commands)) {
            return $this->failure;
        }

        if (in_array('dump-env', $command, true)) {
            file_put_contents($cwd.'/.env.local.php', '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($environment, true).';'.PHP_EOL);
        }

        return new SetupCommandResult(0);
    }
}
