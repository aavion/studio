<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupComposerEnvironment
{
    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    public function create(string $projectDir, array $environment = []): array
    {
        $path = $environment['PATH'] ?? $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH');
        $composerEnvironment = $environment;

        if (is_string($path) && '' !== trim($path)) {
            $composerEnvironment['PATH'] = $path;
        }

        $composerEnvironment['HOME'] = $projectDir.'/var';
        $composerEnvironment['COMPOSER_HOME'] = $projectDir.'/var/composer-home';
        $composerEnvironment['COMPOSER_CACHE_DIR'] = $projectDir.'/var/composer-cache';
        $composerEnvironment['COMPOSER_NO_INTERACTION'] = '1';
        $composerEnvironment['SHELL_VERBOSITY'] = '0';

        return $composerEnvironment;
    }
}
