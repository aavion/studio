<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use Throwable;

final readonly class FileVisitorIdentityStore implements VisitorIdentityStoreInterface
{
    private const FALLBACK_TTL_SECONDS = 3_600;
    private const COOKIE_TTL_SECONDS = 2_592_000;

    public function __construct(
        private string $cacheDir,
        private string $environment,
    ) {
    }

    public function resolve(
        ?string $cookieHash,
        string $fallbackHash,
        ?string $pendingCookieHash,
        string $newVisitorId,
    ): ?string {
        try {
            return $this->resolveLocked($cookieHash, $fallbackHash, $pendingCookieHash, $newVisitorId);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveLocked(
        ?string $cookieHash,
        string $fallbackHash,
        ?string $pendingCookieHash,
        string $newVisitorId,
    ): string {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $newVisitorId;
        }

        $handle = fopen($path, 'c+');

        if (false === $handle || !flock($handle, LOCK_EX)) {
            return $newVisitorId;
        }

        try {
            $data = $this->read($handle);
            $now = time();
            $this->removeExpired($data, $now);
            $visitorId = $this->resolveData($data, $cookieHash, $fallbackHash, $pendingCookieHash, $newVisitorId, $now);
            $this->write($handle, $data);

            return $visitorId;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array{cookies: array<string, array{visitor_id: string, expires_at: int}>, fallbacks: array<string, array{visitor_id: string, expires_at: int}>}
     */
    private function read(mixed $handle): array
    {
        rewind($handle);
        $decoded = json_decode(stream_get_contents($handle) ?: '', true);

        if (!is_array($decoded)) {
            return ['cookies' => [], 'fallbacks' => []];
        }

        return [
            'cookies' => is_array($decoded['cookies'] ?? null) ? $decoded['cookies'] : [],
            'fallbacks' => is_array($decoded['fallbacks'] ?? null) ? $decoded['fallbacks'] : [],
        ];
    }

    /**
     * @param array{cookies: array<string, array{visitor_id: string, expires_at: int}>, fallbacks: array<string, array{visitor_id: string, expires_at: int}>} $data
     */
    private function resolveData(
        array &$data,
        ?string $cookieHash,
        string $fallbackHash,
        ?string $pendingCookieHash,
        string $newVisitorId,
        int $now,
    ): string {
        if (null !== $cookieHash && isset($data['cookies'][$cookieHash])) {
            $data['cookies'][$cookieHash]['expires_at'] = $now + self::COOKIE_TTL_SECONDS;

            return $data['cookies'][$cookieHash]['visitor_id'];
        }

        if (null === $cookieHash && isset($data['fallbacks'][$fallbackHash])) {
            $data['fallbacks'][$fallbackHash]['expires_at'] = $now + self::FALLBACK_TTL_SECONDS;

            return $data['fallbacks'][$fallbackHash]['visitor_id'];
        }

        if (null === $cookieHash) {
            $data['fallbacks'][$fallbackHash] = [
                'visitor_id' => $newVisitorId,
                'expires_at' => $now + self::FALLBACK_TTL_SECONDS,
            ];
        }

        $hash = $cookieHash ?? $pendingCookieHash;

        if (null !== $hash) {
            $data['cookies'][$hash] = [
                'visitor_id' => $newVisitorId,
                'expires_at' => $now + self::COOKIE_TTL_SECONDS,
            ];
        }

        return $newVisitorId;
    }

    /**
     * @param array{cookies: array<string, array{visitor_id: string, expires_at: int}>, fallbacks: array<string, array{visitor_id: string, expires_at: int}>} $data
     */
    private function removeExpired(array &$data, int $now): void
    {
        $data['cookies'] = array_filter($data['cookies'], static fn (array $entry): bool => ($entry['expires_at'] ?? 0) >= $now);
        $data['fallbacks'] = array_filter($data['fallbacks'], static fn (array $entry): bool => ($entry['expires_at'] ?? 0) >= $now);
    }

    /**
     * @param resource $handle
     * @param array{cookies: array<string, array{visitor_id: string, expires_at: int}>, fallbacks: array<string, array{visitor_id: string, expires_at: int}>} $data
     */
    private function write(mixed $handle, array $data): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
        fflush($handle);
    }

    private function path(): string
    {
        return rtrim($this->cacheDir, '/\\').'/'.$this->environment.'/visitor-identities.json';
    }
}
