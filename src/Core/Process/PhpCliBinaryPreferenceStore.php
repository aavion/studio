<?php

declare(strict_types=1);

namespace App\Core\Process;

use App\Core\Environment\DotenvFileEditor;
use Throwable;

final readonly class PhpCliBinaryPreferenceStore
{
    public const KEY = 'APP_DEFAULT_PHP_BINARY';

    public function __construct(private DotenvFileEditor $editor = new DotenvFileEditor())
    {
    }

    public function read(string $projectDir, string $environment): ?string
    {
        $sourceValue = $this->readSourceValue($this->path($projectDir, $environment));
        if (null !== $sourceValue && '' !== trim($sourceValue)) {
            return trim($sourceValue);
        }

        $runtimeValue = $_SERVER[self::KEY] ?? $_ENV[self::KEY] ?? getenv(self::KEY);

        return is_string($runtimeValue) && '' !== trim($runtimeValue) ? trim($runtimeValue) : null;
    }

    /**
     * @return array{persisted: bool, path: string|null, error: string|null}
     */
    public function write(string $projectDir, string $environment, string $binary): array
    {
        $path = $this->path($projectDir, $environment);

        try {
            $contents = '';
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if (false === $contents) {
                    return ['persisted' => false, 'path' => $path, 'error' => 'unreadable'];
                }
            }

            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return ['persisted' => false, 'path' => $path, 'error' => 'directory_unavailable'];
            }

            if (false === @file_put_contents($path, $this->editor->merge($contents, [self::KEY => $binary]), LOCK_EX)) {
                return ['persisted' => false, 'path' => $path, 'error' => 'write_failed'];
            }

            return ['persisted' => true, 'path' => $path, 'error' => null];
        } catch (Throwable $error) {
            return ['persisted' => false, 'path' => $path, 'error' => $error->getMessage()];
        }
    }

    private function readSourceValue(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }

        return $this->editor->readValue($contents, self::KEY);
    }

    private function path(string $projectDir, string $environment): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR.'/\\').'/.env.'.$this->safeEnvironment($environment).'.local';
    }

    private function safeEnvironment(string $environment): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $environment) ?: 'prod';
    }
}
