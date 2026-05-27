<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\Yaml\Yaml;

final class SetupMessageTranslator
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogues = [];

    /**
     * @param array<string, string> $parameters
     */
    public function translate(string $projectDir, string $language, string $key, array $parameters = []): string
    {
        $message = $this->read($projectDir, $language, $key) ?? $this->read($projectDir, 'en', $key) ?? $key;

        return strtr($message, $parameters);
    }

    private function read(string $projectDir, string $language, string $key): ?string
    {
        $data = $this->catalogue($projectDir, $language);

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }

            $data = $data[$segment];
        }

        return is_scalar($data) ? (string) $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogue(string $projectDir, string $language): array
    {
        $cacheKey = $projectDir.'|'.$language;

        if (array_key_exists($cacheKey, $this->catalogues)) {
            return $this->catalogues[$cacheKey];
        }

        $path = $projectDir.'/translations/runtime/messages.'.$language.'.yaml';

        if (!is_file($path)) {
            return $this->catalogues[$cacheKey] = [];
        }

        $data = Yaml::parseFile($path);

        return $this->catalogues[$cacheKey] = is_array($data) ? $data : [];
    }
}
