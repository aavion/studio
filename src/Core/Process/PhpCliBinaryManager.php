<?php

declare(strict_types=1);

namespace App\Core\Process;

final readonly class PhpCliBinaryManager
{
    public function __construct(
        private PhpCliBinaryResolver $resolver = new PhpCliBinaryResolver(),
        private PhpCliBinaryValidator $validator = new PhpCliBinaryValidator(),
        private PhpCliBinaryPreferenceStore $preferenceStore = new PhpCliBinaryPreferenceStore(),
    ) {
    }

    /**
     * @param array<string, string|false> $environment
     */
    public function resolve(
        string $projectDir,
        string $appEnv,
        array $environment = [],
        bool $persistPreference = false,
    ): PhpCliBinaryResolution {
        if ($this->resolver->safeModeEnabled()) {
            return PhpCliBinaryResolution::unavailable('safe_mode_enabled');
        }

        if (!$this->resolver->processFunctionsAvailable()) {
            return PhpCliBinaryResolution::unavailable('process_disabled', [
                'unavailable_functions' => $this->resolver->unavailableProcessFunctions(),
            ]);
        }

        $preferred = $this->preferenceStore->read($projectDir, $appEnv);
        if (null !== $preferred) {
            $preferredValidation = $this->validator->validate([$preferred], $projectDir, $environment);
            if ($preferredValidation->isValid()) {
                return PhpCliBinaryResolution::available([$preferred], [
                    ...$preferredValidation->context(),
                    'source' => 'preferred',
                    'preference_key' => PhpCliBinaryPreferenceStore::KEY,
                ]);
            }
        }

        $resolution = $this->resolver->resolve($projectDir, $this->stringEnvironment($environment));
        if (!$resolution->isAvailable()) {
            return $resolution;
        }

        $validation = $this->validator->validate($resolution->commandPrefix(), $projectDir, $environment);
        if (!$validation->isValid()) {
            return PhpCliBinaryResolution::unavailable($validation->reason(), [
                ...$validation->context(),
                'source' => 'resolved',
                'previous_preference' => $preferred,
            ]);
        }

        $context = [
            ...$validation->context(),
            'source' => null === $preferred ? 'resolved' : 'resolved_after_preference_failed',
            'previous_preference' => $preferred,
            'preference_key' => PhpCliBinaryPreferenceStore::KEY,
        ];

        $preferredBinary = $this->preferredBinary($resolution->commandPrefix());
        if ($persistPreference && null !== $preferredBinary && $preferredBinary !== $preferred) {
            $context['preference_write'] = $this->preferenceStore->write($projectDir, $appEnv, $preferredBinary);
        }

        return PhpCliBinaryResolution::available($resolution->commandPrefix(), $context);
    }

    /**
     * @param list<string> $commandPrefix
     */
    private function preferredBinary(array $commandPrefix): ?string
    {
        if (2 === count($commandPrefix) && '/usr/bin/env' === $commandPrefix[0] && 'php' === $commandPrefix[1]) {
            return 'php';
        }

        if (1 !== count($commandPrefix)) {
            return null;
        }

        $binary = $commandPrefix[0];
        if ('' === trim($binary)) {
            return null;
        }

        if (in_array($binary, ['php', 'php.exe'], true)) {
            return $binary;
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            return preg_match('/^[a-zA-Z]:[\/\\\\]/', $binary) ? $binary : null;
        }

        return str_starts_with($binary, '/') ? $binary : null;
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string>
     */
    private function stringEnvironment(array $environment): array
    {
        $strings = [];
        foreach ($environment as $name => $value) {
            if (is_string($value)) {
                $strings[$name] = $value;
            }
        }

        return $strings;
    }
}
