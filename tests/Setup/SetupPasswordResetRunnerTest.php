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
        $databaseUrl = 'sqlite:///'.$this->databasePath;

        $user = $runner->findUser($this->root, $databaseUrl, 'admin');

        self::assertNotNull($user);
        self::assertSame('00000000-0000-0000-0000-000000000201', $user->uid());
        self::assertSame('admin@example.test', $user->email());

        $result = $runner->reset($this->root, $databaseUrl, 'admin', 'new-password', 'test');

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ActionLog::class, $result->value());

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $row = $pdo
            ->query("SELECT password_hash FROM user_account WHERE username = 'admin'")
            ->fetch(PDO::FETCH_ASSOC);
        $marker = $pdo
            ->query("SELECT marker_by FROM state_marker WHERE subject_type = 'user_account' AND subject_uid = '00000000-0000-0000-0000-000000000201' AND marker_key = 'password_changed'")
            ->fetchColumn();

        self::assertIsArray($row);
        self::assertTrue(password_verify('new-password', (string) $row['password_hash']));
        self::assertSame('test', $marker);
    }

    public function testItReturnsInvalidResultForMissingUser(): void
    {
        $runner = new SetupPasswordResetRunner(new NullWorkflowResultMessageReporter());

        $result = $runner->reset($this->root, 'sqlite:///'.$this->databasePath, 'missing', 'new-password');

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->context()['halt_on_error']);
    }

    private function createSchema(): void
    {
        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE state_marker (uid VARCHAR(36) NOT NULL PRIMARY KEY, subject_type VARCHAR(80) NOT NULL, subject_uid VARCHAR(36) NOT NULL, marker_key VARCHAR(80) NOT NULL, marker_at DATETIME NOT NULL, marker_by VARCHAR(180) DEFAULT NULL, marker_value VARCHAR(255) DEFAULT NULL, metadata CLOB NOT NULL, UNIQUE(subject_type, subject_uid, marker_key))');
        $pdo->exec('CREATE TABLE user_account (uid VARCHAR(36) NOT NULL PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, email VARCHAR(180) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, profile CLOB NOT NULL, settings CLOB NOT NULL, status VARCHAR(32) NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO user_account (uid, username, email, password_hash, profile, settings, status) VALUES (:uid, :username, :email, :password_hash, :profile, :settings, :status)');
        $statement->execute([
            'uid' => '00000000-0000-0000-0000-000000000201',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password_hash' => password_hash('old-password', PASSWORD_DEFAULT),
            'profile' => '{}',
            'settings' => '{"language":"default"}',
            'status' => 'active',
        ]);
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
