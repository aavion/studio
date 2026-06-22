<?php

declare(strict_types=1);

namespace App\Core\Extension;

interface ExtensionCacheInterface
{
    public const DEFAULT_TTL_SECONDS = 3600;
    public const MAX_TTL_SECONDS = 604800;
    public const MAX_VALUE_BYTES = 1048576;

    public function set(string $extensionName, string $key, mixed $value, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool;

    public function get(string $extensionName, string $key, mixed $default = null): mixed;

    public function delete(string $extensionName, string $key): bool;
}
