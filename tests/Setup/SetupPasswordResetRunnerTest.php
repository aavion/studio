<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Core\ActionLog\ActionLog;
use App\Setup\SetupPasswordResetRunner;
use PDO;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PHPUnit\Framework\TestCase;

final class SetupPasswordResetRunnerTest extends TestCase
{
    private string $root;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio_password_reset_'.bin2hex(random_bytes(4));
        mkdir($this->root.'/var', 0777, true);
        $this->databasePath = $this->root.'/var/reset.db';
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItFindsAndResetsUserPassword(): void
    {
        $runner = new SetupPasswordResetRunner(new NullWorkflowResultMessageReporter());
        $databaseUrl = $this->sqliteUrl($this->databasePath);

        $user = $runner->findUser($this->root, $databaseUrl, 'admin');

        self::assertNotNull($user);
        self::assertSame('00000000-0000-7000-8000-000000000201', $user->uid());
        self::assertSame('admin@example.test', $user->email());

        $result = $runner->reset($this->root, $databaseUrl, 'admin', 'NewPassword1!', 'test');

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ActionLog::class, $result->value());

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $row = $pdo
            ->query("SELECT password_hash FROM user_account WHERE username = 'admin'")
            ->fetch(PDO::FETCH_ASSOC);
        $marker = $pdo
            ->query("SELECT marker_by FROM state_marker WHERE subject_type = 'user_account' AND subject_uid = '00000000-0000-7000-8000-000000000201' AND marker_key = 'password_changed'")
            ->fetchColumn();

        self::assertIsArray($row);
        self::assertTrue(password_verify('NewPassword1!', (string) $row['password_hash']));
        self::assertSame('test', $marker);
    }

    public function testItReturnsInvalidResultForMissingUser(): void
    {
        $runner = new SetupPasswordResetRunner(new NullWorkflowResultMessageReporter());

        $result = $runner->reset($this->root, $this->sqliteUrl($this->databasePath), 'missing', 'NewPassword1!');

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
    }

    public function testItHonorsDatabasePrefixWhenFindingAndResettingPasswords(): void
    {
        $databasePath = $this->root.'/var/prefixed-reset.db';
        $this->createSchema($databasePath, 'studio_');
        $runner = new SetupPasswordResetRunner(new NullWorkflowResultMessageReporter());
        $databaseUrl = $this->sqliteUrl($databasePath);

        $user = $runner->findUser($this->root, $databaseUrl, 'admin', 'studio_');
        $result = $runner->reset($this->root, $databaseUrl, 'admin', 'NewPassword1!', 'test', 'studio_');

        self::assertNotNull($user);
        self::assertTrue($result->isSuccess());

        $pdo = new PDO('sqlite:'.$databasePath);
        $row = $pdo
            ->query("SELECT password_hash FROM studio_user_account WHERE username = 'admin'")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertTrue(password_verify('NewPassword1!', (string) $row['password_hash']));
    }

    public function testItReturnsInvalidResultForPrefixedUsersWithoutDatabasePrefix(): void
    {
        $databasePath = $this->root.'/var/prefixed-missing-reset.db';
        $this->createSchema($databasePath, 'studio_');
        $runner = new SetupPasswordResetRunner(new NullWorkflowResultMessageReporter());

        $result = $runner->reset($this->root, $this->sqliteUrl($databasePath), 'admin', 'NewPassword1!');

        self::assertFalse($result->isSuccess());
        self::assertSame('E_INVALID_ARGUMENT', $result->firstIssue()?->code());
    }

    private function createSchema(?string $databasePath = null, string $prefix = ''): void
    {
        $pdo = new PDO('sqlite:'.($databasePath ?? $this->databasePath));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(sprintf('CREATE TABLE %sstate_marker (uid VARCHAR(36) NOT NULL PRIMARY KEY, subject_type VARCHAR(80) NOT NULL, subject_uid VARCHAR(36) NOT NULL, marker_key VARCHAR(80) NOT NULL, marker_at DATETIME NOT NULL, marker_by VARCHAR(180) DEFAULT NULL, marker_value VARCHAR(255) DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(subject_type, subject_uid, marker_key))', $prefix));
        $pdo->exec(sprintf('CREATE TABLE %suser_account (uid VARCHAR(36) NOT NULL PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, email VARCHAR(180) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, profile CLOB NOT NULL, settings CLOB NOT NULL, status VARCHAR(32) NOT NULL)', $prefix));
        $statement = $pdo->prepare(sprintf('INSERT INTO %suser_account (uid, username, email, password_hash, profile, settings, status) VALUES (:uid, :username, :email, :password_hash, :profile, :settings, :status)', $prefix));
        $statement->execute([
            'uid' => '00000000-0000-7000-8000-000000000201',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password_hash' => password_hash('old-password', PASSWORD_DEFAULT),
            'profile' => '{}',
            'settings' => '{"language":"default"}',
            'status' => 'active',
        ]);
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
