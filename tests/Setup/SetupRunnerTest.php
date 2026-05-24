<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Core\ActionLog\ActionLog;
use App\Setup\DatabaseDriver;
use App\Setup\DatabaseUrlFactory;
use App\Setup\SetupCommandExecutorInterface;
use App\Setup\SetupCommandResult;
use App\Setup\SetupInput;
use App\Setup\SetupLanguageCatalog;
use App\Setup\SetupRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class SetupRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio-setup-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/bin', 0777, true);
        mkdir($this->root.'/translations', 0777, true);
        mkdir($this->root.'/var', 0777, true);
        touch($this->root.'/bin/console');
        file_put_contents($this->root.'/translations/messages.en.yaml', "message: []\n");
        file_put_contents($this->root.'/translations/messages.de.yaml', "message: []\n");
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
        $runner = new SetupRunner($this->root, $executor);

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
        self::assertSame([
            ['composer', '--version'],
            ['composer', 'dump-env', 'test'],
            [PHP_BINARY, $this->root.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--env=test'],
        ], $executor->commands);

        $pdo = new PDO('sqlite:'.$databasePath);
        $title = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'site.title'")->fetchColumn();
        $url = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'site.url'")->fetchColumn();
        $language = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'localization.default_language'")->fetchColumn();
        $homePath = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'content.home_path'")->fetchColumn();
        $defaultAclGroup = $pdo->query("SELECT value FROM config_entry WHERE config_key = 'user.default_acl_group'")->fetchColumn();
        $aclGroups = $pdo->query('SELECT identifier, access_level, locked, allow_empty FROM acl_group ORDER BY access_level')->fetchAll(PDO::FETCH_ASSOC);
        $passwordHash = $pdo->query("SELECT password_hash FROM user_account WHERE username = 'admin'")->fetchColumn();
        $stateMarkers = $pdo->query("SELECT marker_key, marker_value FROM state_marker WHERE subject_type = 'user_account' ORDER BY marker_key")->fetchAll(PDO::FETCH_KEY_PAIR);
        $groups = $pdo->query("SELECT g.identifier FROM acl_group g INNER JOIN user_acl_group ug ON ug.group_uid = g.uid INNER JOIN user_account u ON u.uid = ug.user_uid WHERE u.username = 'admin' ORDER BY g.access_level")->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame('Example Studio', json_decode((string) $title, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('https://example.test', json_decode((string) $url, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('de', json_decode((string) $language, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('/home', json_decode((string) $homePath, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('registered', json_decode((string) $defaultAclGroup, true, flags: JSON_THROW_ON_ERROR));
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
    }

    public function testItStopsOnCommandFailureAndReturnsActionLogContext(): void
    {
        $executor = new RecordingSetupCommandExecutor(failureAt: 2, failure: new SetupCommandResult(1, '', 'dump-env failed'));
        $runner = new SetupRunner($this->root, $executor);

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

    public function testItUsesBundledComposerWhenSystemComposerIsUnavailable(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        touch($this->root.'/bin/composer');
        $executor = new RecordingSetupCommandExecutor(failureAt: 1, failure: new SetupCommandResult(1));
        $runner = new SetupRunner($this->root, $executor);

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
        ], $executor->commands);
    }

    public function testDryRunReturnsPlannedChangesWithoutWriting(): void
    {
        $databasePath = $this->root.'/var/setup.db';
        $this->createSchema($databasePath);
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, $executor);

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
    }

    public function testDryRunMasksDatabasePasswordsInActionLogContext(): void
    {
        $executor = new RecordingSetupCommandExecutor();
        $runner = new SetupRunner($this->root, $executor);

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
        $runner = new SetupRunner($this->root, $executor);

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

        return new SetupCommandResult(0);
    }
}
