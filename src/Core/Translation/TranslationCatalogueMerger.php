<?php

declare(strict_types=1);

namespace App\Core\Translation;

use Symfony\Component\Yaml\Yaml;

final readonly class TranslationCatalogueMerger
{
    /**
     * @param list<array{locale: string, path: string}> $sources
     *
     * @return array{catalogues: array<string, array<string, mixed>>, files: int}
     */
    public function mergeSources(array $sources): array
    {
        $catalogues = [];
        $files = 0;

        foreach ($sources as $source) {
            $locale = $source['locale'];
            $catalogues[$locale] ??= [];
            $catalogues[$locale] = $this->merge($catalogues[$locale], $this->readYaml($source['path']), $source['path']);
            ++$files;
        }

        return [
            'catalogues' => $catalogues,
            'files' => $files,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readYaml(string $path): array
    {
        $data = Yaml::parseFile($path);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private function merge(array $left, array $right, string $source, string $prefix = ''): array
    {
        foreach ($right as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (!array_key_exists($key, $left)) {
                $left[$key] = $value;
                continue;
            }

            if (is_array($left[$key]) && is_array($value)) {
                $left[$key] = $this->merge($left[$key], $value, $source, $path);
                continue;
            }

            throw new TranslationCatalogueCollisionException($path, $source);
        }

        return $left;
    }
}
