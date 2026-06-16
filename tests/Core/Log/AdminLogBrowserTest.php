<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\AdminLogBrowser;
use App\Core\Log\DatabaseLogBrowser;
use App\Core\Log\LogFileBrowser;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AdminLogBrowserTest extends TestCase
{
    use FilesystemTestHelper;

    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = $this->createTemporaryDirectory('system-admin-log-browser');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDir);
    }

    public function testItCombinesApplicationFileLogWithDatabaseSources(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE message_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, code VARCHAR(160) DEFAULT NULL, context CLOB NOT NULL)');
        $this->writeTestFile($this->logDir, 'test.log', '[2099-01-01T10:00:00.000000+00:00] app.ERROR: app.failure {"code":"app.failure","request_id":"application-request"} []'.PHP_EOL);

        $browser = new AdminLogBrowser(
            new DatabaseLogBrowser($connection),
            new LogFileBrowser($this->logDir, 'test'),
        );

        $applicationView = $browser->browse(['source' => 'application', 'level' => 'ERROR', 'q' => 'application-request']);
        self::assertSame(['application', 'message', 'audit', 'access', 'security_signal'], array_column($applicationView['sources'], 'key'));
        self::assertSame('application', $applicationView['selected_source']);
        self::assertTrue($applicationView['capabilities']['level_filter']);
        self::assertSame(1, $applicationView['pagination']['total']);
        self::assertSame('app.failure', $applicationView['entries'][0]['message']);

        $applicationEntry = $browser->entry('application', $applicationView['entries'][0]['id']);
        self::assertNotNull($applicationEntry);
        self::assertSame('app.failure', $applicationEntry['message']);
    }
}
