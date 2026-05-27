<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupCompletionMarker;
use App\Setup\SetupStepFailedException;
use PHPUnit\Framework\TestCase;

final class SetupCompletionMarkerTest extends TestCase
{
    private string $root;
    private mixed $previousServerValue = null;
    private mixed $previousEnvValue = null;
    private mixed $previousPutenvValue = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio-setup-marker-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $this->previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $this->previousPutenvValue = getenv(SetupCompletionMarker::KEY);
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
        $this->restoreEnvironment();
    }

    public function testItKeepsSetupOpenWithoutMarker(): void
    {
        self::assertFalse((new SetupCompletionMarker())->isComplete($this->root, 'test'));
    }

    public function testItMarksSetupCompleteInDumpedEnvironment(): void
    {
        file_put_contents($this->root.'/.env.local.php', "<?php\n\nreturn ['APP_SECRET' => 'existing'];\n");
        $marker = new SetupCompletionMarker();

        $result = $marker->markComplete($this->root, 'test');

        self::assertSame(['APP_SETUP_COMPLETED'], $result['keys']);
        $environment = include $this->root.'/.env.local.php';
        self::assertSame('existing', $environment['APP_SECRET']);
        self::assertSame('1', $environment['APP_SETUP_COMPLETED']);
        self::assertFalse($marker->isComplete($this->root, 'test'));
        $_SERVER[SetupCompletionMarker::KEY] = '1';
        self::assertTrue($marker->isComplete($this->root, 'test'));
    }

    public function testItReportsWriteFailuresWithStructuredMessages(): void
    {
        mkdir($this->root.'/.env.local.php');

        try {
            (new SetupCompletionMarker())->markComplete($this->root, 'test');
            self::fail('Expected setup completion marker write failure.');
        } catch (SetupStepFailedException $exception) {
            self::assertSame('message.setup.environment_file_write_failed', $exception->messageObject()?->translationKey());
            self::assertSame(['%file%' => '.env.local.php'], $exception->messageObject()?->parameters());
        }
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);

        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $child = $path.'/'.$item;

            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }

    private function restoreEnvironment(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);

        if (null !== $this->previousServerValue) {
            $_SERVER[SetupCompletionMarker::KEY] = $this->previousServerValue;
        }

        if (null !== $this->previousEnvValue) {
            $_ENV[SetupCompletionMarker::KEY] = $this->previousEnvValue;
        }

        if (is_string($this->previousPutenvValue)) {
            putenv(SetupCompletionMarker::KEY.'='.$this->previousPutenvValue);

            return;
        }

        putenv(SetupCompletionMarker::KEY);
    }
}
