<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\LogFileBrowser;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class LogFileBrowserTest extends TestCase
{
    use FilesystemTestHelper;

    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = $this->createTemporaryDirectory('studio-log-browser');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDir);
    }

    public function testItReadsAndFiltersSelectedLogFiles(): void
    {
        $this->writeTestFile($this->logDir, 'test.studio-message-2026-05-27.log', implode(PHP_EOL, [
            '[2026-05-27T10:00:00.000000+00:00] studio_message.INFO: message.package.discovery_completed {"code":"package.discovery_completed"} []',
            '[2026-05-27T10:01:00.000000+00:00] studio_message.ERROR: message.process.command_failed {"code":"process.command_failed","package":"demo-module"} []',
            '',
        ]));

        $view = (new LogFileBrowser($this->logDir, 'test'))->browse([
            'source' => 'message',
            'level' => 'ERROR',
            'q' => 'demo-module',
        ]);

        self::assertSame('message', $view['selected_source']);
        self::assertSame('ERROR', $view['filters']['level']);
        self::assertCount(1, $view['entries']);
        self::assertSame('message.process.command_failed', $view['entries'][0]['message']);
        self::assertSame('process.command_failed', $view['entries'][0]['context']['code']);
        self::assertSame('test.studio-message-2026-05-27.log', $view['entries'][0]['file']);
    }

    public function testItReadsAccessContextColumns(): void
    {
        $this->writeTestFile($this->logDir, 'test.studio-access-2026-05-27.log', '[2026-05-27T10:00:00.000000+00:00] studio_access.INFO: access.request {"method":"GET","path":"/admin/logs","route":"backend_admin_route","http_status":200,"ip":"127.0.0.1","city":"n/a","state":"n/a","country":"n/a","continent":"n/a"} []'.PHP_EOL);

        $view = (new LogFileBrowser($this->logDir, 'test'))->browse([
            'source' => 'access',
        ]);

        self::assertCount(1, $view['entries']);
        self::assertSame('/admin/logs', $view['entries'][0]['context']['path']);
        self::assertSame('n/a', $view['entries'][0]['context']['country']);
    }
}
