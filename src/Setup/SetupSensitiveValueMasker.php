<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupSensitiveValueMasker
{
    public function maskDatabaseUrl(string $databaseUrl): string
    {
        $parts = parse_url($databaseUrl);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['pass'])) {
            return $databaseUrl;
        }

        $user = isset($parts['user']) ? rawurlencode((string) $parts['user']).':' : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return sprintf('%s://%s[hidden]@%s%s%s%s%s', $parts['scheme'], $user, $parts['host'], $port, $path, $query, $fragment);
    }
}
