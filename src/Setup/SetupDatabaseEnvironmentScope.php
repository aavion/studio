<?php

declare(strict_types=1);

namespace App\Setup;

use App\Database\DatabaseReadyState;

final class SetupDatabaseEnvironmentScope
{
    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    public function commandEnvironment(array $environment): array
    {
        return [
            ...$environment,
            DatabaseReadyState::ALLOW_UNREADY_KEY => '1',
        ];
    }

    /**
     * @param array<string, string> $environment
     * @param callable(): array<string, mixed> $callback
     *
     * @return array<string, mixed>
     */
    public function run(array $environment, callable $callback): array
    {
        $environment = $this->commandEnvironment($environment);
        $previous = [];

        foreach ($environment as $name => $value) {
            $previous[$name] = [
                'server_exists' => array_key_exists($name, $_SERVER),
                'server_value' => $_SERVER[$name] ?? null,
                'env_exists' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
                'process_value' => getenv($name),
            ];
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $name => $state) {
                if ($state['server_exists']) {
                    $_SERVER[$name] = $state['server_value'];
                } else {
                    unset($_SERVER[$name]);
                }

                if ($state['env_exists']) {
                    $_ENV[$name] = $state['env_value'];
                } else {
                    unset($_ENV[$name]);
                }

                if (false === $state['process_value']) {
                    putenv($name);
                } else {
                    putenv($name.'='.$state['process_value']);
                }
            }
        }
    }
}
