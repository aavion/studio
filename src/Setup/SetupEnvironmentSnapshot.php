<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupEnvironmentSnapshot
{
    /**
     * @param array<string, array{exists: bool, contents: string|null}> $files
     */
    public function __construct(private array $files)
    {
    }

    public static function capture(string $projectDir, string $environment): self
    {
        $files = [];

        foreach ([self::environmentPath($projectDir, $environment), self::dumpPath($projectDir)] as $path) {
            $exists = is_file($path) && !is_link($path);
            $contents = $exists ? file_get_contents($path) : null;
            $files[$path] = [
                'exists' => $exists,
                'contents' => is_string($contents) ? $contents : null,
            ];
        }

        return new self($files);
    }

    /**
     * @return array{removed: list<string>, restored: list<string>}
     */
    public function restore(): array
    {
        $removed = [];
        $restored = [];

        foreach ($this->files as $path => $state) {
            if ($state['exists']) {
                file_put_contents($path, (string) $state['contents'], LOCK_EX);
                $restored[] = basename($path);

                continue;
            }

            if (is_file($path) && !is_link($path)) {
                unlink($path);
                $removed[] = basename($path);
            }
        }

        return ['removed' => $removed, 'restored' => $restored];
    }

    private static function environmentPath(string $projectDir, string $environment): string
    {
        return rtrim($projectDir, '/').'/.env.'.$environment.'.local';
    }

    private static function dumpPath(string $projectDir): string
    {
        return rtrim($projectDir, '/').'/.env.local.php';
    }
}
