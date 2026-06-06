<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use RuntimeException;
use Throwable;

final readonly class LiveOperationRunStorage
{
    private const STATUS_QUEUED = 'queued';
    private const STATUS_RUNNING = 'running';

    public function __construct(
        private string $projectDir,
        private string $environment,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $operationId): ?array
    {
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $path = $this->path($operationId);

        if (!is_file($path)) {
            return null;
        }

        try {
            $contents = file_get_contents($path);

            if (!is_string($contents)) {
                return null;
            }

            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    public function write(string $operationId, array $state): void
    {
        if (!$this->validOperationId($operationId)) {
            throw new RuntimeException('Live operation id is invalid.');
        }

        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Live operation directory "%s" could not be created.', $directory));
        }

        $state['updated_at'] = $this->now();

        file_put_contents($this->path($operationId), json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    public function mutate(string $operationId, callable $mutator): void
    {
        $state = $this->read($operationId);

        if (null === $state) {
            throw new RuntimeException(sprintf('Live operation "%s" does not exist.', $operationId));
        }

        $this->write($operationId, $mutator($state));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claimQueued(string $operationId, string $token): ?array
    {
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $path = $this->path($operationId);

        if (!is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'c+');

        if (false === $handle) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }

            $contents = stream_get_contents($handle, offset: 0);
            $state = is_string($contents) && '' !== $contents
                ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
                : null;

            if (!is_array($state)
                || !hash_equals((string) ($state['token'] ?? ''), $token)
                || self::STATUS_QUEUED !== (string) ($state['status'] ?? '')
            ) {
                return null;
            }

            $state['status'] = self::STATUS_RUNNING;
            $state['started_at'] ??= $this->now();
            $state['updated_at'] = $this->now();
            $state['progress'] = ['index' => 0, 'total' => 0];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $state;
        } catch (Throwable) {
            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function directory(): string
    {
        return rtrim($this->projectDir, '/').'/var/operations/'.$this->safeEnvironment();
    }

    public function outputPath(string $operationId): string
    {
        if (!$this->validOperationId($operationId)) {
            throw new RuntimeException('Live operation id is invalid.');
        }

        return $this->directory().'/'.$operationId.'.out';
    }

    public function pidPath(string $operationId): string
    {
        if (!$this->validOperationId($operationId)) {
            throw new RuntimeException('Live operation id is invalid.');
        }

        return $this->directory().'/'.$operationId.'.pid';
    }

    public function validOperationId(string $operationId): bool
    {
        return 1 === preg_match('/^[a-f0-9]{32}$/', $operationId);
    }

    private function path(string $operationId): string
    {
        return $this->directory().'/'.$operationId.'.json';
    }

    private function safeEnvironment(): string
    {
        $environment = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($this->environment));

        return is_string($environment) && '' !== $environment ? $environment : 'default';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
