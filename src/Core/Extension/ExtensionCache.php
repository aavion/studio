<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Validation\IdentifierSpec;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

final readonly class ExtensionCache implements ExtensionCacheInterface
{
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function set(string $extensionName, string $key, mixed $value, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
    {
        $cacheKey = $this->cacheKey($extensionName, $key);
        if (null === $cacheKey || $ttlSeconds < 1 || $ttlSeconds > self::MAX_TTL_SECONDS || !$this->isSupportedValue($value)) {
            return false;
        }

        try {
            $item = $this->cache->getItem($cacheKey);
            $item->expiresAfter($ttlSeconds);
            $item->set([
                'extension' => $extensionName,
                'key' => $key,
                'value' => $value,
            ]);

            return $this->cache->save($item);
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $extensionName, string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->cacheKey($extensionName, $key);
        if (null === $cacheKey) {
            return $default;
        }

        try {
            $item = $this->cache->getItem($cacheKey);
            if (!$item->isHit()) {
                return $default;
            }

            $payload = $item->get();
            if (
                !is_array($payload)
                || ($payload['extension'] ?? null) !== $extensionName
                || ($payload['key'] ?? null) !== $key
                || !array_key_exists('value', $payload)
            ) {
                return $default;
            }

            return $payload['value'];
        } catch (Throwable) {
            return $default;
        }
    }

    public function delete(string $extensionName, string $key): bool
    {
        $cacheKey = $this->cacheKey($extensionName, $key);
        if (null === $cacheKey) {
            return false;
        }

        try {
            return $this->cache->deleteItem($cacheKey);
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheKey(string $extensionName, string $key): ?string
    {
        $extensionName = trim($extensionName);
        $key = trim($key);

        if (!ExtensionManifestSpec::isValidSlug($extensionName) || !IdentifierSpec::isMachineIdentifier($key)) {
            return null;
        }

        return 'extension.'.$extensionName.'.'.hash('sha256', $key);
    }

    private function isSupportedValue(mixed $value, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }

        if (null === $value || is_scalar($value)) {
            return $this->serializedSizeAllowed($value);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                return false;
            }

            if (!$this->isSupportedValue($item, $depth + 1)) {
                return false;
            }
        }

        return $this->serializedSizeAllowed($value);
    }

    private function serializedSizeAllowed(mixed $value): bool
    {
        try {
            return strlen(serialize($value)) <= self::MAX_VALUE_BYTES;
        } catch (Throwable) {
            return false;
        }
    }
}
