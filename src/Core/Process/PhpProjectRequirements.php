<?php

declare(strict_types=1);

namespace App\Core\Process;

final readonly class PhpProjectRequirements
{
    public function minimumPhpVersion(string $projectDir): ?string
    {
        $versions = [];

        $composer = $this->readJsonFile($projectDir.'/composer.json');
        if (null !== $composer) {
            $versions[] = $composer['require']['php'] ?? null;
        }

        $lock = $this->readJsonFile($projectDir.'/composer.lock');
        if (null !== $lock) {
            $versions[] = $lock['platform']['php'] ?? null;

            foreach (['packages', 'packages-dev'] as $section) {
                foreach (($lock[$section] ?? []) as $package) {
                    if (is_array($package)) {
                        $versions[] = $package['require']['php'] ?? null;
                    }
                }
            }
        }

        $minimum = null;
        foreach ($versions as $constraint) {
            if (!is_string($constraint)) {
                continue;
            }

            $candidate = $this->minimumVersionFromConstraint($constraint);
            if (null !== $candidate && (null === $minimum || version_compare($candidate, $minimum, '>'))) {
                $minimum = $candidate;
            }
        }

        return $minimum;
    }

    /**
     * @return list<string>
     */
    public function requiredPhpExtensions(string $projectDir): array
    {
        $composer = $this->readJsonFile($projectDir.'/composer.json');
        $required = [];

        foreach ($this->phpExtensionRequirements($composer['require'] ?? []) as $extension) {
            $required[] = $extension;
        }

        $lock = $this->readJsonFile($projectDir.'/composer.lock');
        foreach (($lock['packages'] ?? []) as $package) {
            if (is_array($package)) {
                foreach ($this->phpExtensionRequirements($package['require'] ?? []) as $extension) {
                    $required[] = $extension;
                }
            }
        }

        sort($required);

        return array_values(array_unique($required));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $requirements
     *
     * @return list<string>
     */
    private function phpExtensionRequirements(mixed $requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }

        $extensions = [];
        foreach ($requirements as $name => $constraint) {
            if (is_string($name) && str_starts_with($name, 'ext-')) {
                $extensions[] = substr($name, strlen('ext-'));
            }
        }

        return $extensions;
    }

    private function minimumVersionFromConstraint(string $constraint): ?string
    {
        $alternatives = preg_split('/\s*\|\|?\s*/', $constraint) ?: [$constraint];
        $minimum = null;

        foreach ($alternatives as $alternative) {
            $candidate = $this->minimumVersionFromConstraintAlternative($alternative);
            if (null !== $candidate && (null === $minimum || version_compare($candidate, $minimum, '<'))) {
                $minimum = $candidate;
            }
        }

        return $minimum;
    }

    private function minimumVersionFromConstraintAlternative(string $constraint): ?string
    {
        $minimum = null;
        if (preg_match_all('/(?:>=|\^|~)\s*v?([0-9]+(?:\.[0-9]+){1,2})/', $constraint, $matches)) {
            foreach ($matches[1] as $version) {
                $version = $this->normalizeVersion($version);
                if (null === $minimum || version_compare($version, $minimum, '>')) {
                    $minimum = $version;
                }
            }
        }

        if (null === $minimum && preg_match('/^\s*v?([0-9]+(?:\.[0-9]+){1,2})(?:\s|$|-)/', $constraint, $match)) {
            $minimum = $this->normalizeVersion($match[1]);
        }

        return $minimum;
    }

    private function normalizeVersion(string $version): string
    {
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
