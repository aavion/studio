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
        $this->logDir = $this->createTemporaryDirectory('system-log-browser');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDir);
    }

    public function testItReadsAndFiltersSelectedLogFiles(): void
    {
        $this->writeTestFile($this->logDir, 'test/message-2099-01-01.log', implode(PHP_EOL, [
            '[2099-01-01T10:00:00.000000+00:00] message.INFO: message.package.discovery_completed {"code":"package.discovery_completed"} []',
            '[2099-01-01T10:01:00.000000+00:00] message.ERROR: message.process.command_failed {"code":"process.command_failed","package":"demo-module"} []',
            '',
        ]));

        $view = (new LogFileBrowser($this->logDir, 'test'))->browse([
            'source' => 'message',
            'level' => 'ERROR',
            'q' => 'demo-module',
        ]);

        self::assertSame('message', $view['selected_source']);
        self::assertSame('ERROR', $view['filters']['level']);
        self::assertSame('24h', $view['filters']['time_window']);
        self::assertSame(50, $view['filters']['per_page']);
        self::assertSame(1, $view['pagination']['total']);
        self::assertCount(1, $view['entries']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $view['entries'][0]['id']);
        self::assertSame('message.process.command_failed', $view['entries'][0]['message']);
        self::assertSame('process.command_failed', $view['entries'][0]['context']['code']);
        self::assertSame('message-2099-01-01.log', $view['entries'][0]['file']);

        $entry = (new LogFileBrowser($this->logDir, 'test'))->entry('message', $view['entries'][0]['id']);

        self::assertNotNull($entry);
        self::assertSame('message.process.command_failed', $entry['message']);
    }

    public function testItReadsAccessContextColumns(): void
    {
        $this->writeTestFile($this->logDir, 'test/access-2099-01-01.log', '[2099-01-01T10:00:00.000000+00:00] access.INFO: access.request {"method":"GET","path":"/admin/logs","route":"backend_admin_route","http_status":200,"ip":"127.0.0.1","city":"n/a","state":"n/a","country":"n/a","continent":"n/a"} []'.PHP_EOL);

        $view = (new LogFileBrowser($this->logDir, 'test'))->browse([
            'source' => 'access',
        ]);

        self::assertCount(1, $view['entries']);
        self::assertSame('/admin/logs', $view['entries'][0]['context']['path']);
        self::assertSame('n/a', $view['entries'][0]['context']['country']);
    }

    public function testItUsesClampedPaginationPageWhenReadingEntries(): void
    {
        $lines = [];
        for ($i = 1; $i <= 26; ++$i) {
            $lines[] = sprintf('[2099-01-01T10:%02d:00.000000+00:00] message.ERROR: message.%02d [] []', $i, $i);
        }
        $this->writeTestFile($this->logDir, 'test/message-2099-01-01.log', implode(PHP_EOL, [...$lines, '']));

        $view = (new LogFileBrowser($this->logDir, 'test'))->browse([
            'source' => 'message',
            'level' => 'ERROR',
            'per_page' => 25,
            'page' => 999,
        ]);

        self::assertSame(2, $view['filters']['page']);
        self::assertSame(2, $view['pagination']['page']);
        self::assertCount(1, $view['entries']);
        self::assertSame('message.01', $view['entries'][0]['message']);
    }
}
