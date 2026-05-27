<?php

declare(strict_types=1);

namespace App\Localization;

use App\Core\Translation\TranslationCatalogueCollisionException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class CoreTranslationBootstrapper
{
    public const SOURCE_DIRECTORY = 'translations/languages';
    public const TARGET_PATTERN = 'translations/runtime/messages.%s.yaml';

    /**
     * @return array{success: bool, locales: list<string>, files: int, error?: string}
     */
    public function generate(string $projectDir): array
    {
        try {
            return $this->doGenerate($projectDir);
        } catch (Throwable $error) {
            return [
                'success' => false,
                'locales' => [],
                'files' => 0,
                'error' => $error->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, locales: list<string>, files: int}
     */
    private function doGenerate(string $projectDir): array
    {
        $sourceRoot = $projectDir.'/'.self::SOURCE_DIRECTORY;
        if (!is_dir($sourceRoot)) {
            return [
                'success' => true,
                'locales' => [],
                'files' => 0,
            ];
        }

        $locales = [];
        $files = 0;

        foreach ($this->localeDirectories($sourceRoot) as $locale => $directory) {
            $catalogue = [];
            foreach ($this->yamlFiles($directory) as $file) {
                $catalogue = $this->merge($catalogue, $this->readYaml($file), $file);
                ++$files;
            }

            $target = $projectDir.'/'.sprintf(self::TARGET_PATTERN, $locale);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            file_put_contents($target, Yaml::dump($catalogue, 6, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $locales[] = $locale;
        }

        sort($locales);

        return [
            'success' => true,
            'locales' => $locales,
            'files' => $files,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function localeDirectories(string $sourceRoot): array
    {
        $directories = [];

        foreach (glob($sourceRoot.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locale = basename($directory);
            if (1 === preg_match('/^[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*$/', $locale)) {
                $directories[$locale] = $directory;
            }
        }

        ksort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function yamlFiles(string $directory): array
    {
        $files = glob($directory.'/*.yaml') ?: [];
        sort($files);

        return $files;
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

            if (array_key_exists($key, $left) && is_array($left[$key]) && is_array($value)) {
                $left[$key] = $this->merge($left[$key], $value, $source, $path);
                continue;
            }

            if (array_key_exists($key, $left)) {
                throw new TranslationCatalogueCollisionException($path, $source);
            }

            $left[$key] = $value;
        }

        return $left;
    }
}
