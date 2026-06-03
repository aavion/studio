<?php

declare(strict_types=1);

namespace App\Core\Process;

final class CliProcessEnvironment
{
    private const WEB_EXACT_NAMES = [
        'AUTH_TYPE',
        'CONTENT_LENGTH',
        'CONTENT_TYPE',
        'DOCUMENT_ROOT',
        'FCGI_ROLE',
        'GATEWAY_INTERFACE',
        'HTTPS',
        'PATH_INFO',
        'PATH_TRANSLATED',
        'PHP_AUTH_DIGEST',
        'PHP_AUTH_PW',
        'PHP_AUTH_TYPE',
        'PHP_AUTH_USER',
        'QUERY_STRING',
        'REMOTE_ADDR',
        'REMOTE_HOST',
        'REMOTE_IDENT',
        'REMOTE_PORT',
        'REMOTE_USER',
        'REQUEST_METHOD',
        'REQUEST_SCHEME',
        'REQUEST_TIME',
        'REQUEST_TIME_FLOAT',
        'REQUEST_URI',
        'SCRIPT_FILENAME',
        'SCRIPT_NAME',
    ];

    private const WEB_PREFIXES = [
        'HTTP_',
        'REDIRECT_',
        'SERVER_',
    ];

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string|false>
     */
    public static function withoutWebContext(array $environment = []): array
    {
        return [
            ...self::webContextRemovals(),
            ...$environment,
        ];
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string|false>
     */
    public static function removeWebContextFrom(array $environment): array
    {
        return [
            ...$environment,
            ...self::webContextRemovals($environment),
        ];
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, false>
     */
    private static function webContextRemovals(array $environment = []): array
    {
        $removals = [];
        foreach (self::environmentNames($environment) as $name) {
            if (self::isWebContextName($name)) {
                $removals[$name] = false;
            }
        }

        foreach (self::WEB_EXACT_NAMES as $name) {
            $removals[$name] = false;
        }

        return $removals;
    }

    /**
     * @return list<string>
     */
    private static function environmentNames(array $environment = []): array
    {
        $names = [];
        foreach ([getenv(), $_SERVER, $_ENV, $environment] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $name => $_value) {
                if (is_string($name) && '' !== trim($name)) {
                    $names[$name] = $name;
                }
            }
        }

        return array_values($names);
    }

    private static function isWebContextName(string $name): bool
    {
        if (in_array($name, self::WEB_EXACT_NAMES, true)) {
            return true;
        }

        foreach (self::WEB_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
