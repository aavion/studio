<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Setup\SetupMessageCode;
use App\Setup\SetupMessageKey;

final readonly class SetupCompletionMarker
{
    public const KEY = 'APP_SETUP_COMPLETED';
    private const DUMPED_ENV_FILE = '.env.local.php';

    public function isComplete(string $projectDir, string $environment): bool
    {
        $currentValue = $_SERVER[self::KEY] ?? $_ENV[self::KEY] ?? getenv(self::KEY);

        return $this->truthy($currentValue);
    }

    /**
     * @return array<string, mixed>
     */
    public function markComplete(string $projectDir, string $environment): array
    {
        $path = $this->dumpedEnvironmentPath($projectDir);
        $environmentValues = $this->readDumpedEnvironment($path);
        $bytes = @file_put_contents($path, $this->dumpedEnvironmentContents([
            ...$environmentValues,
            self::KEY => '1',
        ]), LOCK_EX);

        if (false === $bytes) {
            throw $this->failure(SetupMessageCode::SETUP_ENVIRONMENT_FILE_WRITE_FAILED, SetupMessageKey::SETUP_ENVIRONMENT_FILE_WRITE_FAILED, $path);
        }

        return ['path' => basename($path), 'keys' => [self::KEY]];
    }

    private function dumpedEnvironmentPath(string $projectDir): string
    {
        return $projectDir.'/'.self::DUMPED_ENV_FILE;
    }

    private function truthy(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value, " \t\n\r\0\x0B'\"")), ['1', 'true', 'yes'], true);
    }

    /**
     * @return array<string, string>
     */
    private function readDumpedEnvironment(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = include $path;

        if (!is_array($values)) {
            throw $this->failure(SetupMessageCode::SETUP_ENVIRONMENT_FILE_UNREADABLE, SetupMessageKey::SETUP_ENVIRONMENT_FILE_UNREADABLE, $path);
        }

        $environment = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $environment[$key] = (string) $value;
            }
        }

        return $environment;
    }

    /**
     * @param array<string, string> $values
     */
    private function dumpedEnvironmentContents(array $values): string
    {
        return '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($values, true).';'.PHP_EOL;
    }

    private function failure(string $code, string $translationKey, string $path): SetupStepFailedException
    {
        return SetupStepFailedException::fromMessage(Message::error(
            $code,
            $translationKey,
            ['%file%' => basename($path)],
            ['path' => $path],
        ));
    }
}
