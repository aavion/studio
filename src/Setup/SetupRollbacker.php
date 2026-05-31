<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupRollbacker
{
    /**
     * @return array<string, mixed>
     */
    public function rollback(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        if ($input->dryRun()) {
            return ['rollback' => ['skipped' => 'dry_run']];
        }

        return [
            'rollback' => [
                'env_files_removed' => $this->removeEnvironmentFiles($projectDir, $input->appEnv()),
                'sqlite_files_removed' => $this->removeSqliteFiles($projectDir, $input, $databaseUrl),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function removeEnvironmentFiles(string $projectDir, string $environment): array
    {
        $removed = [];

        foreach ([$projectDir.'/.env.'.$environment.'.local', $projectDir.'/.env.local.php'] as $path) {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
                $removed[] = basename($path);
            }
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function removeSqliteFiles(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        if (DatabaseDriver::SQLite !== $input->databaseDriver() || !str_starts_with($databaseUrl, 'sqlite:///')) {
            return [];
        }

        $path = rawurldecode(substr($databaseUrl, strlen('sqlite:///')));
        $path = str_replace(
            ['%kernel.project_dir%', '%kernel.environment%'],
            [$projectDir, $input->appEnv()],
            $path,
        );

        if (!str_starts_with($path, '/')) {
            $path = $projectDir.'/'.$path;
        }

        $path = $this->normalizedPath($path);
        $projectRoot = realpath($projectDir);
        $varRoot = $this->normalizedPath($projectDir.'/var');

        if (null === $path || null === $varRoot || !is_string($projectRoot) || !str_starts_with($path, $varRoot.'/')) {
            return [];
        }

        $removed = [];
        foreach ([$path, $path.'-journal', $path.'-wal', $path.'-shm'] as $candidate) {
            if (is_file($candidate) && !is_link($candidate)) {
                unlink($candidate);
                $removed[] = substr($candidate, strlen($projectRoot) + 1);
            }
        }

        return $removed;
    }

    private function normalizedPath(string $path): ?string
    {
        $directory = dirname($path);
        $base = basename($path);
        $realDirectory = realpath($directory);

        if (!is_string($realDirectory)) {
            return null;
        }

        return $realDirectory.'/'.$base;
    }
}
