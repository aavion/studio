<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Core\ActionLog\ActionLog;
use App\Core\Asset\AssetMessageCode;
use App\Core\Asset\AssetMessageKey;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Process\PhpCliBinaryPreferenceStore;
use App\Core\Process\PhpCliBinaryValidator;
use App\Database\DatabaseReadyState;
use App\Setup\DatabaseDriver;
use App\Setup\DatabaseUrlFactory;
use App\Setup\SetupCommandExecutorInterface;
use App\Setup\SetupCommandResult;
use App\Setup\SetupDefaultSeed;
use App\Setup\SetupInput;
use App\Setup\SetupLanguageCatalog;
use App\Setup\SetupRunner;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class SetupRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/system-backend-setup-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/bin', 0777, true);
        mkdir($this->root.'/config/packages', 0777, true);
        mkdir($this->root.'/translations/runtime/test', 0777, true);
        mkdir($this->root.'/var', 0777, true);
        touch($this->root.'/bin/console');
        file_put_contents($this->root.'/config/packages/translation.yaml', "framework:\n    default_locale: en\n");
        file_put_contents($this->root.'/translations/runtime/test/messages.en.yaml', "message: []\n");
        file_put_contents($this->root.'/translations/runtime/test/messages.de.yaml', "message: []\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItRunsSetupAndSeedsConfigurationAndAdmin(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $composerEnvironments = [];
        $assetRebuildEnvironment = null;
        $root = $this->root;
        $executor = new RecordingSetupCommandExecutor(onRun: static function (array $command, string $_cwd, array $environment) use (&$composerEnvironments, &$assetRebuildEnvironment): void {
            if (in_array('--version', $command, true) || in_array('dump-env', $command, true)) {
                $composerEnvironments[] = $environment;
            }

            if (in_array('assets:rebuild', $command, true)) {
                $assetRebuildEnvironment = $environment;
            }
        });
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);
        $input = new SetupInput(
            appEnv: 'test',
            language: 'de',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        );
        $seed = new SetupDefaultSeed();

        $result = $runner->run($input);

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ActionLog::class, $result->value());
        self::assertFalse($result->context()['halt_on_error']);
        self::assertFileExists($this->root.'/.env.test.local');
        self::assertStringContainsString("APP_SECRET='test-setup-app-secret-not-secure'", (string) file_get_contents($this->root.'/.env.test.local'));
        self::assertStringNotContainsString('MERCURE_JWT_SECRET', (string) file_get_contents($this->root.'/.env.test.local'));
        $storedPhpBinary = (new PhpCliBinaryPreferenceStore())->read($this->root, 'test');
        self::assertIsString($storedPhpBinary);
        self::assertTrue((new PhpCliBinaryValidator())->validate([$storedPhpBinary], $this->root)->isValid());
        self::assertFileExists($this->root.'/.env.local.php');
        $dumpedEnvironment = include $this->root.'/.env.local.php';
        self::assertSame('1', $dumpedEnvironment['APP_SETUP_COMPLETED']);
        self::assertSame($root.'/var/composer-home', $composerEnvironments[0]['COMPOSER_HOME'] ?? null);
        self::assertSame($root.'/var/composer-cache', $composerEnvironments[0]['COMPOSER_CACHE_DIR'] ?? null);
        self::assertSame($root.'/var', $composerEnvironments[0]['HOME'] ?? null);
        self::assertSame('0', $composerEnvironments[0]['SHELL_VERBOSITY'] ?? null);
        self::assertSame($root.'/var/composer-home', $composerEnvironments[1]['COMPOSER_HOME'] ?? null);
        self::assertSame($root.'/var/composer-cache', $composerEnvironments[1]['COMPOSER_CACHE_DIR'] ?? null);
        self::assertSame($root.'/var', $composerEnvironments[1]['HOME'] ?? null);
        self::assertSame('0', $composerEnvironments[1]['SHELL_VERBOSITY'] ?? null);
        self::assertIsArray($assetRebuildEnvironment);
        self::assertSame('test', $assetRebuildEnvironment['APP_ENV'] ?? null);
        self::assertFalse($assetRebuildEnvironment['APP_DEBUG'] ?? null);
        self::assertSame('0', $assetRebuildEnvironment['SHELL_VERBOSITY'] ?? null);
        self::assertSame('1', $assetRebuildEnvironment[DatabaseReadyState::ALLOW_UNREADY_KEY] ?? null);
        self::assertFalse($assetRebuildEnvironment['APP_SECRET'] ?? null);
        self::assertFalse($assetRebuildEnvironment['DATABASE_URL'] ?? null);
        self::assertFalse($assetRebuildEnvironment['APP_DATABASE_PREFIX'] ?? null);
        self::assertFalse($assetRebuildEnvironment['DEFAULT_URI'] ?? null);
        self::assertSame('test-setup-app-secret-not-secure', $executor->environments[6]['MERCURE_JWT_SECRET'] ?? null);
        self::assertSame('test-setup-app-secret-not-secure', $executor->environments[7]['MERCURE_JWT_SECRET'] ?? null);
        self::assertSame([
            ['composer', '--version'],
            ['composer', 'dump-env', 'test'],
            [PHP_BINARY, $this->root.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'cache:clear', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'packages:discover', '--run-now', '--trigger=setup', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'assets:rebuild', '--trigger=setup', '--env=test', '--json'],
            [PHP_BINARY, $this->root.'/bin/console', 'mercure:stop', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'mercure:health', '--env=test'],
        ], $executor->commands);

        $pdo = new PDO('sqlite:'.$databasePath);
        $configRows = $pdo->query('SELECT config_key, value FROM config_entry')->fetchAll(PDO::FETCH_KEY_PAIR);
        $geoIpLicenseSensitive = $pdo->query(sprintf(
            "SELECT sensitive FROM config_entry WHERE config_key = '%s'",
            MaxMindGeoIpConfig::LICENSE_KEY_KEY,
        ))->fetchColumn();
        $aclGroups = $pdo->query('SELECT identifier, min_role FROM acl_group WHERE json_extract(metadata, "$.seeded_by") = "setup" ORDER BY min_role')->fetchAll(PDO::FETCH_ASSOC);
        $adminUser = $pdo->query("SELECT password_hash, role FROM user_account WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
        $stateMarkers = $pdo->query("SELECT marker_key, marker_value FROM state_marker WHERE subject_type = 'user_account' ORDER BY marker_key")->fetchAll(PDO::FETCH_KEY_PAIR);
        $groups = $pdo->query("SELECT g.identifier FROM acl_group g INNER JOIN user_acl_group ug ON ug.group_uid = g.uid INNER JOIN user_account u ON u.uid = ug.user_uid WHERE u.username = 'admin' ORDER BY g.min_role")->fetchAll(PDO::FETCH_COLUMN);
        $home = $pdo->query(sprintf("SELECT ci.slug, ci.status, ci.visibility, ci.active_revision_uid, ci.available_languages, cs.identifier AS schema_identifier FROM content_item ci INNER JOIN content_schema cs ON cs.uid = ci.schema_uid WHERE ci.slug = '%s'", $seed->homeContentItem([$input->language()])['slug']))->fetch(PDO::FETCH_ASSOC);
        $homeTitle = $pdo->query(sprintf("SELECT field_content FROM content_field_value WHERE revision_uid = '%s' AND field_identifier = 'title' AND language = '%s'", $seed->homeContentRevision()['uid'], $input->language()))->fetchColumn();

        self::assertSame($seed->configMap($input), $this->decodedConfigRows($configRows, array_keys($seed->configMap($input))));
        self::assertSame(1, (int) $geoIpLicenseSensitive);
        self::assertSame(array_map(static fn (array $group): array => [
            'identifier' => $group['identifier'],
            'min_role' => $group['min_role'],
        ], $seed->aclGroups()), array_map(static fn (array $row): array => [
            'identifier' => $row['identifier'],
            'min_role' => (int) $row['min_role'],
        ], $aclGroups));
        self::assertIsArray($adminUser);
        self::assertTrue(password_verify('Secret1!password', (string) $adminUser['password_hash']));
        self::assertSame('owner', $adminUser['role']);
        self::assertSame([
            'created' => null,
            'password_changed' => null,
            'status_changed' => 'active',
        ], $stateMarkers);
        self::assertSame([], $groups);
        $homeContent = $seed->homeContentItem([$input->language()]);
        $homeRevision = $seed->homeContentRevision();
        $schema = $seed->contentSchema();
        self::assertSame([
            'slug' => $homeContent['slug'],
            'status' => $homeContent['status'],
            'visibility' => $homeContent['visibility'],
            'active_revision_uid' => $homeRevision['uid'],
            'available_languages' => json_encode($homeContent['available_languages'], JSON_THROW_ON_ERROR),
            'schema_identifier' => $schema['identifier'],
        ], $home);
        self::assertSame($seed->homeContentFields($input)['title'][$input->language()], json_decode((string) $homeTitle, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testItDoesNotRunMercureHealthWhenMercureStopFails(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor(onRun: static function (array $command): ?SetupCommandResult {
            if (in_array('mercure:stop', $command, true)) {
                return new SetupCommandResult(1, '', 'stop failed');
            }

            return null;
        });
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertTrue($result->isSuccess());
        self::assertContainsEquals([PHP_BINARY, $this->root.'/bin/console', 'mercure:stop', '--env=test'], $executor->commands);
        self::assertNotContainsEquals([PHP_BINARY, $this->root.'/bin/console', 'mercure:health', '--env=test'], $executor->commands);

        $log = $result->value();
        self::assertInstanceOf(ActionLog::class, $log);
        $entries = $log->toArray()['entries'];
        self::assertSame('run_mercure_health', $entries[11]['name']);
        self::assertFalse($entries[11]['context']['stopped']);
        self::assertFalse($entries[11]['context']['available']);
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
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'short',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('setup.admin_password.too_short', $result->firstIssue()?->code());
        self::assertSame([], $executor->commands);
    }

    public function testItRejectsShortAppSecretBeforeSetupSteps(): void
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
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'short',
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('setup.app_secret.too_short', $result->firstIssue()?->code());
        self::assertSame([], $executor->commands);
    }

    public function testItSeedsPrefixedDatabaseTablesInRunnerProcess(): void
    {
        $databasePath = $this->root.'/var/setup-prefixed.db';
        $this->createSchema($databasePath, 'studio_');
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), new RecordingSetupCommandExecutor());

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Prefixed Studio',
            defaultUri: 'https://prefixed.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            databasePrefix: 'studio_',
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertTrue($result->isSuccess());

        $pdo = new PDO('sqlite:'.$databasePath);
        self::assertSame(
            'Prefixed Studio',
            json_decode((string) $pdo->query("SELECT value FROM studio_config_entry WHERE config_key = 'site.title'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM studio_user_account WHERE username = 'admin'")->fetchColumn());
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
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertTrue($result->isSuccess());
        self::assertFileDoesNotExist($this->root.'/var/data_%kernel.environment%.db');

        $pdo = new PDO('sqlite:'.$databasePath);
        self::assertSame(
            'Placeholder Studio',
            json_decode((string) $pdo->query("SELECT value FROM config_entry WHERE config_key = 'site.title'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM acl_group')->fetchColumn());
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
            databaseUrl: $this->sqliteUrl($this->root.'/var/missing-schema.db'),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('seed_default_settings', $result->context()['failed_step']);
        self::assertSame(
            'config.write_failed',
            $result->context()['action_log']['entries'][5]['issues'][0]['code'],
        );
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
            databaseUrl: $this->sqliteUrl($this->root.'/var/setup.db'),
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
        self::assertSame('dump_environment', $result->context()['failed_step']);
        self::assertArrayHasKey('action_log', $result->context());
        self::assertFileDoesNotExist($this->root.'/.env.test.local');
        self::assertFileDoesNotExist($this->root.'/.env.local.php');
    }

    public function testItRollsBackGeneratedFilesAndSqliteTablesWhenFinalCacheClearFails(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $executor = new RecordingSetupCommandExecutor(
            failureAt: 4,
            failure: new SetupCommandResult(1, '', 'cache clear failed'),
            onRun: function (array $command) use ($databasePath): void {
                if (in_array('doctrine:migrations:migrate', $command, true)) {
                    $this->createSchema($databasePath);
                    $pdo = new PDO('sqlite:'.$databasePath);
                    $pdo->exec('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY)');
                }
            },
        );
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('clear_cache', $result->context()['failed_step']);
        self::assertFileDoesNotExist($this->root.'/.env.test.local');
        self::assertFileDoesNotExist($this->root.'/.env.local.php');
        self::assertFileExists($databasePath);
        self::assertSame(['.env.test.local', '.env.local.php'], $result->context()['rollback']['env_files_removed']);
        self::assertSame([], $result->context()['rollback']['sqlite_files_removed']);
        self::assertContains('config_entry', $result->context()['rollback']['database_tables_removed']['tables']);
        self::assertContains('doctrine_migration_versions', $result->context()['rollback']['database_tables_removed']['tables']);
        self::assertSame('setup.rollback_completed', $result->context()['action_log']['entries'][8]['messages'][0]['code']);

        $pdo = new PDO('sqlite:'.$databasePath);
        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('config_entry', 'user_account', 'doctrine_migration_versions')")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testItDoesNotDropPreExistingTablesWhenRollbackRuns(): void
    {
        $databasePath = $this->root.'/var/existing-setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor(failureAt: 4, failure: new SetupCommandResult(1, '', 'cache clear failed'));
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertFalse($result->isSuccess());
        self::assertNotContains('config_entry', $result->context()['rollback']['database_tables_removed']['tables']);
        self::assertContains('config_entry', $result->context()['rollback']['database_tables_removed']['skipped']);

        $pdo = new PDO('sqlite:'.$databasePath);
        self::assertSame(
            ['config_entry', 'user_account'],
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('config_entry', 'user_account') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testItRestoresPreExistingEnvironmentFilesWhenRollbackRuns(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        file_put_contents($this->root.'/.env.test.local', "EXISTING_SECRET='keep-me'\nDEFAULT_URI='https://old.example.test'\n");
        file_put_contents($this->root.'/.env.local.php', "<?php\n\nreturn ['EXISTING_SECRET' => 'keep-me'];\n");
        $executor = new RecordingSetupCommandExecutor(failureAt: 4, failure: new SetupCommandResult(1, '', 'cache clear failed'));
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame([], $result->context()['rollback']['env_files_removed']);
        self::assertSame(['.env.test.local', '.env.local.php'], $result->context()['rollback']['env_files_restored']);
        self::assertSame("EXISTING_SECRET='keep-me'\nDEFAULT_URI='https://old.example.test'\n", file_get_contents($this->root.'/.env.test.local'));
        self::assertSame("<?php\n\nreturn ['EXISTING_SECRET' => 'keep-me'];\n", file_get_contents($this->root.'/.env.local.php'));
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
            databaseUrl: $this->sqliteUrl($this->root.'/var/setup.db'),
            appSecret: 'test-setup-app-secret-not-secure',
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

    public function testItFallsBackToSystemComposerWhenBundledComposerIsUnavailable(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        touch($this->root.'/bin/composer');
        chmod($this->root.'/bin/composer', 0755);
        $executor = new RecordingSetupCommandExecutor(failureAt: 1, failure: new SetupCommandResult(1));
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([
            [PHP_BINARY, $this->root.'/bin/composer', '--version'],
            ['composer', '--version'],
            ['composer', 'dump-env', 'test'],
            [PHP_BINARY, $this->root.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'cache:clear', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'packages:discover', '--run-now', '--trigger=setup', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'assets:rebuild', '--trigger=setup', '--env=test', '--json'],
            [PHP_BINARY, $this->root.'/bin/console', 'mercure:stop', '--env=test'],
            [PHP_BINARY, $this->root.'/bin/console', 'mercure:health', '--env=test'],
        ], $executor->commands);
    }

    public function testDryRunReturnsPlannedChangesWithoutWriting(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);
        $input = new SetupInput(
            appEnv: 'test',
            language: 'de',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            dryRun: true,
        );
        $seed = new SetupDefaultSeed();

        $result = $runner->run($input);

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
        self::assertSame($seed->configMap($input), $entries[4]['context']['settings']);
        self::assertSame('seed_initial_content', $entries[6]['name']);
        self::assertSame($seed->homePath(), $entries[6]['context']['path']);
        self::assertSame($seed->contentSchema()['identifier'], $entries[6]['context']['schema']);
        self::assertSame('clear_cache', $entries[7]['name']);
        self::assertSame([PHP_BINARY, $this->root.'/bin/console', 'cache:clear', '--env=test'], $entries[7]['context']['command']);
        self::assertSame('run_package_discovery', $entries[8]['name']);
        self::assertSame([PHP_BINARY, $this->root.'/bin/console', 'packages:discover', '--run-now', '--trigger=setup', '--env=test'], $entries[8]['context']['command']);
        self::assertSame('run_asset_rebuild', $entries[9]['name']);
        self::assertSame([PHP_BINARY, $this->root.'/bin/console', 'assets:rebuild', '--trigger=setup', '--env=test', '--json'], $entries[9]['context']['command']);
        self::assertSame('run_mercure_health', $entries[10]['name']);
        self::assertSame([PHP_BINARY, $this->root.'/bin/console', 'mercure:stop', '--env=test'], $entries[10]['context']['stop_command']);
        self::assertSame([PHP_BINARY, $this->root.'/bin/console', 'mercure:health', '--env=test'], $entries[10]['context']['command']);
        self::assertSame('mark_setup_completed', $entries[11]['name']);
    }

    public function testDryRunUsesPhpCliPlaceholderWhenResolverValidationFails(): void
    {
        unlink($this->root.'/bin/console');

        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), new RecordingSetupCommandExecutor());
        $input = new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($this->root.'/var/setup.db'),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            dryRun: true,
        );

        $result = $runner->run($input);

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ActionLog::class, $result->value());
        $entries = $result->value()->toArray()['entries'];
        self::assertSame('run_migrations', $entries[3]['name']);
        self::assertSame('php-cli-unavailable:console_unreadable', $entries[3]['context']['command'][0]);
    }

    public function testItSurfacesNonBlockingAssetRebuildWarningsInSetupActionLog(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor(onRun: static function (array $command, string $_cwd, array $_environment): ?SetupCommandResult {
            if (!in_array('assets:rebuild', $command, true)) {
                return null;
            }

            return new SetupCommandResult(0, json_encode([
                'action_log' => [
                    'entries' => [
                        [
                            'name' => 'Build Tailwind CSS',
                            'status' => 'success',
                            'messages' => [
                                [
                                    'level' => 'WARN',
                                    'code' => AssetMessageCode::TAILWIND_BUILD_DEFERRED,
                                    'translation_key' => AssetMessageKey::TAILWIND_BUILD_DEFERRED,
                                    'parameters' => [
                                        '%command%' => 'php bin/console tailwind:build',
                                    ],
                                    'context' => [
                                        'command' => ['php', 'bin/console', 'tailwind:build'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'result' => [
                    'status' => 'success',
                    'messages' => [],
                ],
            ], JSON_THROW_ON_ERROR));
        });
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Example Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            appSecret: 'test-setup-app-secret-not-secure',
        ));

        self::assertTrue($result->isSuccess());

        $log = $result->value();
        self::assertInstanceOf(ActionLog::class, $log);
        $entries = $log->toArray()['entries'];

        self::assertSame('run_asset_rebuild', $entries[10]['name']);
        self::assertSame(AssetMessageCode::TAILWIND_BUILD_DEFERRED, $entries[10]['messages'][0]['code']);
    }

    public function testDryRunDoesNotCreateMissingSqliteDatabaseDuringPreparation(): void
    {
        $databasePath = $this->root.'/var/missing-dry-run.db';
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'de',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($databasePath),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            dryRun: true,
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([], $executor->commands);
        self::assertFileDoesNotExist($databasePath);
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
        self::assertSame('message.setup.database_url.sqlite_format_invalid', $result->issues()[0]->translationKey());
        self::assertSame('prepare_setup', $result->issues()[0]->context()['step'] ?? null);
    }

    public function testDryRunRollbackHandlesStepFailuresAfterPreparation(): void
    {
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, new NullWorkflowResultMessageReporter(), $executor);

        $result = $runner->run(new SetupInput(
            appEnv: 'test',
            language: 'fr',
            siteTitle: 'Dry Studio',
            defaultUri: 'https://dry.example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: $this->sqliteUrl($this->root.'/var/missing-dry-run.db'),
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            dryRun: true,
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('select_language', $result->context()['failed_step']);
        self::assertSame(['skipped' => 'dry_run'], $result->context()['rollback']);
        self::assertSame([], $executor->commands);
    }

    public function testLanguageCatalogDiscoversTranslationCatalogues(): void
    {
        $catalog = new SetupLanguageCatalog();

        self::assertSame(['de', 'en'], $catalog->availableLanguages($this->root, 'test'));
        self::assertSame('en', $catalog->defaultLanguage($this->root, 'test'));
        self::assertTrue($catalog->supports($this->root, 'de', 'test'));
        self::assertFalse($catalog->supports($this->root, 'fr', 'test'));
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

    /**
     * @param array<string, string> $rows
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private function decodedConfigRows(array $rows, array $keys): array
    {
        $decoded = [];

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $rows);
            $decoded[$key] = json_decode($rows[$key], true, flags: JSON_THROW_ON_ERROR);
        }

        return $decoded;
    }

    private function createSchema(string $databasePath, string $prefix = ''): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(sprintf('CREATE TABLE %sconfig_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %sacl_group (uid VARCHAR(36) NOT NULL PRIMARY KEY, identifier VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, min_role INTEGER NOT NULL, metadata CLOB NOT NULL)', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %sstate_marker (uid VARCHAR(36) NOT NULL PRIMARY KEY, subject_type VARCHAR(80) NOT NULL, subject_uid VARCHAR(36) NOT NULL, marker_key VARCHAR(80) NOT NULL, marker_at DATETIME NOT NULL, marker_by VARCHAR(180) DEFAULT NULL, marker_value VARCHAR(255) DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(subject_type, subject_uid, marker_key))', $prefix));
        $pdo->exec(sprintf("CREATE TABLE %suser_account (uid VARCHAR(36) NOT NULL PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, email VARCHAR(180) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, profile CLOB NOT NULL, settings CLOB NOT NULL, status VARCHAR(32) NOT NULL, role VARCHAR(40) NOT NULL DEFAULT 'user')", $prefix));
        $pdo->exec(sprintf('CREATE TABLE %suser_acl_group (user_uid VARCHAR(36) NOT NULL, group_uid VARCHAR(36) NOT NULL, PRIMARY KEY(user_uid, group_uid))', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %scontent_schema (uid VARCHAR(36) NOT NULL PRIMARY KEY, identifier VARCHAR(120) NOT NULL UNIQUE, source VARCHAR(255) NOT NULL, locked BOOLEAN NOT NULL, active_version_uid VARCHAR(36) DEFAULT NULL, labels CLOB NOT NULL, descriptions CLOB NOT NULL, metadata CLOB NOT NULL)', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %scontent_schema_version (uid VARCHAR(36) NOT NULL PRIMARY KEY, schema_uid VARCHAR(36) NOT NULL, version INTEGER NOT NULL, title CLOB NOT NULL, description CLOB NOT NULL, definition CLOB NOT NULL, custom_twig CLOB DEFAULT NULL, definition_hash VARCHAR(64) NOT NULL, use_min_level INTEGER DEFAULT NULL, use_group_identifiers CLOB DEFAULT NULL, edit_min_level INTEGER DEFAULT NULL, edit_group_identifiers CLOB DEFAULT NULL, manage_min_level INTEGER DEFAULT NULL, manage_group_identifiers CLOB DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(schema_uid, version))', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %scontent_item (uid VARCHAR(36) NOT NULL PRIMARY KEY, slug VARCHAR(160) NOT NULL, status VARCHAR(255) NOT NULL, parent_uid VARCHAR(36) NOT NULL DEFAULT \'/\', sort_order INTEGER NOT NULL, custom_url VARCHAR(1024) DEFAULT NULL UNIQUE, redirect_target VARCHAR(1024) DEFAULT NULL, schema_uid VARCHAR(36) DEFAULT NULL, schema_version INTEGER DEFAULT NULL, active_revision_uid VARCHAR(36) DEFAULT NULL, version INTEGER NOT NULL, available_languages CLOB NOT NULL, available_variants CLOB NOT NULL, visibility VARCHAR(255) NOT NULL, acl_restrictions CLOB NOT NULL, view_min_level INTEGER DEFAULT NULL, view_group_identifiers CLOB DEFAULT NULL, edit_min_level INTEGER DEFAULT NULL, edit_group_identifiers CLOB DEFAULT NULL, manage_min_level INTEGER DEFAULT NULL, manage_group_identifiers CLOB DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(parent_uid, slug))', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %scontent_revision (uid VARCHAR(36) NOT NULL PRIMARY KEY, content_uid VARCHAR(36) NOT NULL, version INTEGER NOT NULL, schema_uid VARCHAR(36) NOT NULL, schema_version_uid VARCHAR(36) NOT NULL, change_summary VARCHAR(255) DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(content_uid, version))', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %scontent_field_value (uid VARCHAR(36) NOT NULL PRIMARY KEY, revision_uid VARCHAR(36) NOT NULL, language VARCHAR(16) NOT NULL, variant VARCHAR(80) NOT NULL, field_identifier VARCHAR(160) NOT NULL, field_content CLOB NOT NULL, UNIQUE(revision_uid, language, variant, field_identifier))', $prefix));
    }

    private function sqliteUrl(string $path): string
    {
        return 'sqlite:///'.str_replace('\\', '/', $path);
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
            if ($file->isLink()) {
                unlink($file->getPathname());
                continue;
            }

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

    /**
     * @var list<array<string, string|false>>
     */
    public array $environments = [];

    public function __construct(
        private readonly ?int $failureAt = null,
        private readonly ?SetupCommandResult $failure = null,
        private readonly mixed $onRun = null,
    )
    {
    }

    public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
    {
        $this->commands[] = $command;
        $this->environments[] = $environment;
        if (is_callable($this->onRun)) {
            $result = ($this->onRun)($command, $cwd, $environment);

            if ($result instanceof SetupCommandResult) {
                return $result;
            }
        }

        if (null !== $this->failure && $this->failureAt === count($this->commands)) {
            return $this->failure;
        }

        if (in_array('--version', $command, true) && (in_array('composer', $command, true) || str_ends_with($command[1] ?? '', '/bin/composer'))) {
            return new SetupCommandResult(0, 'Composer version test');
        }

        if (in_array('dump-env', $command, true)) {
            file_put_contents($cwd.'/.env.local.php', '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($environment, true).';'.PHP_EOL);
        }

        return new SetupCommandResult(0);
    }
}
