<?php

declare(strict_types=1);

namespace App\Core\Process;

use Symfony\Component\Process\Process;
use Throwable;

final readonly class PhpCliBinaryResolver
{
    /**
     * @param array<string, string> $environment
     */
    public function resolve(?string $workingDirectory = null, array $environment = []): PhpCliBinaryResolution
    {
        if ($this->safeModeEnabled()) {
            return PhpCliBinaryResolution::unavailable('safe_mode_enabled');
        }

        if (!$this->processFunctionsAvailable()) {
            return PhpCliBinaryResolution::unavailable('process_disabled');
        }

        $sawNonCliBinary = false;

        foreach ($this->candidates() as $candidate) {
            $result = $this->testCandidate($candidate, $workingDirectory, $environment);
            if ('ok' === $result) {
                return PhpCliBinaryResolution::available($candidate);
            }

            if ('not_cli' === $result) {
                $sawNonCliBinary = true;
            }
        }

        return PhpCliBinaryResolution::unavailable($sawNonCliBinary ? 'server_config' : 'binary_not_found');
    }

    /**
     * @return list<list<string>>
     */
    private function candidates(): array
    {
        $candidates = [];
        $this->appendCandidate($candidates, PHP_BINARY);
        $this->appendCandidate($candidates, PHP_BINDIR.DIRECTORY_SEPARATOR.$this->phpExecutableName());
        $this->appendCandidate($candidates, $this->phpExecutableName());

        if ('\\' !== DIRECTORY_SEPARATOR) {
            $this->appendCandidate($candidates, '/usr/bin/env', 'php');
            $this->appendCandidate($candidates, '/usr/bin/php');
        }

        return $candidates;
    }

    /**
     * @param list<list<string>> $candidates
     */
    private function appendCandidate(array &$candidates, string ...$command): void
    {
        $command = array_values(array_filter(array_map('trim', $command), static fn (string $part): bool => '' !== $part));
        if ([] === $command) {
            return;
        }

        $signature = implode("\0", $command);
        foreach ($candidates as $candidate) {
            if (implode("\0", $candidate) === $signature) {
                return;
            }
        }

        $candidates[] = $command;
    }

    private function phpExecutableName(): string
    {
        return '\\' === DIRECTORY_SEPARATOR ? 'php.exe' : 'php';
    }

    /**
     * @param list<string> $candidate
     * @param array<string, string> $environment
     */
    private function testCandidate(array $candidate, ?string $workingDirectory, array $environment): string
    {
        try {
            $process = new Process(
                [...$candidate, '-r', 'exit(PHP_SAPI === "cli" ? 0 : 12);'],
                $workingDirectory,
                $this->processEnvironment($environment),
                timeout: 5.0,
            );
            $process->run();
        } catch (Throwable) {
            return 'failed';
        }

        if ($process->isSuccessful()) {
            return 'ok';
        }

        return 12 === $process->getExitCode() ? 'not_cli' : 'failed';
    }

    public function safeModeEnabled(): bool
    {
        $safeMode = ini_get('safe_mode');

        return is_string($safeMode) && in_array(strtolower($safeMode), ['1', 'on', 'true', 'yes'], true);
    }

    public function processFunctionsAvailable(): bool
    {
        return [] === $this->unavailableProcessFunctions();
    }

    /**
     * @return list<string>
     */
    public function unavailableProcessFunctions(): array
    {
        $disabledFunctions = array_filter(array_map('trim', explode(',', strtolower((string) ini_get('disable_functions')))));
        $unavailable = [];

        foreach (['proc_open', 'proc_close', 'proc_get_status', 'proc_terminate'] as $function) {
            if (!function_exists($function) || in_array($function, $disabledFunctions, true)) {
                $unavailable[] = $function;
            }
        }

        return $unavailable;
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private function processEnvironment(array $environment): array
    {
        $inheritedEnvironment = [
            ...$this->scalarEnvironment(getenv()),
            ...$this->scalarEnvironment($_SERVER),
            ...$this->scalarEnvironment($_ENV),
        ];

        return [
            ...CliProcessEnvironment::removeWebContextFrom($inheritedEnvironment),
            ...$environment,
        ];
    }

    /**
     * @param array<mixed>|false $environment
     *
     * @return array<string, string>
     */
    private function scalarEnvironment(array|false $environment): array
    {
        if (false === $environment) {
            return [];
        }

        $scalars = [];
        foreach ($environment as $name => $value) {
            if (!is_string($name) || '' === trim($name) || !is_scalar($value)) {
                continue;
            }

            $scalars[$name] = (string) $value;
        }

        return $scalars;
    }
}
