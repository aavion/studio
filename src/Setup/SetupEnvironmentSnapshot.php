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
     * @return array{removed: list<string>, restored: list<string>, errors: list<array{file: string, error: string}>}
     */
    public function restore(): array
    {
        $removed = [];
        $restored = [];
        $errors = [];

        foreach ($this->files as $path => $state) {
            try {
                if ($state['exists']) {
                    if (false === @file_put_contents($path, (string) $state['contents'], LOCK_EX)) {
                        $errors[] = [
                            'file' => basename($path),
                            'error' => 'Unable to restore environment file.',
                        ];

                        continue;
                    }

                    $restored[] = basename($path);

                    continue;
                }

                if (is_file($path) && !is_link($path)) {
                    if (!@unlink($path)) {
                        $errors[] = [
                            'file' => basename($path),
                            'error' => 'Unable to remove generated environment file.',
                        ];

                        continue;
                    }

                    $removed[] = basename($path);
                }
            } catch (\Throwable $throwable) {
                $errors[] = [
                    'file' => basename($path),
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        return ['removed' => $removed, 'restored' => $restored, 'errors' => $errors];
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
