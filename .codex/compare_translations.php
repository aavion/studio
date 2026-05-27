<?php
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

function flatten(array $data, string $prefix = ''): array
{
    $flat = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += flatten($value, $path);
        } else {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

function merge_catalogue(array $left, array $right, string $source, string $prefix = ''): array
{
    foreach ($right as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (!array_key_exists($key, $left)) {
            $left[$key] = $value;
            continue;
        }

        if (is_array($left[$key]) && is_array($value)) {
            $left[$key] = merge_catalogue($left[$key], $value, $source, $path);
            continue;
        }

        throw new RuntimeException(sprintf('duplicate key "%s" in %s', $path, $source));
    }

    return $left;
}

function merged_locale_catalogue(string $directory): array
{
    $catalogue = [];
    $files = glob($directory.'/*.yaml') ?: [];
    sort($files);

    foreach ($files as $file) {
        $catalogue = merge_catalogue($catalogue, (array) Yaml::parseFile($file), $file);
    }

    return $catalogue;
}

function locale_directories(string $translationDirectory): array
{
    $directories = [];

    foreach (glob($translationDirectory.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $locale = basename($directory);
        if (1 === preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', $locale)) {
            $directories[$locale] = $directory;
        }
    }

    ksort($directories);

    return $directories;
}

function source_catalogues(string $directory): array
{
    $catalogues = [];

    foreach (glob($directory.'/*.yaml') ?: [] as $path) {
        $catalogues[basename($path, '.yaml')] = $path;
    }

    ksort($catalogues);

    return $catalogues;
}

$root = dirname(__DIR__);
$translationDirectory = $root.'/translations/languages';
$localeDirectories = locale_directories($translationDirectory);
$referenceLocale = isset($localeDirectories['en']) ? 'en' : array_key_first($localeDirectories);
$hasIssues = false;

if (null === $referenceLocale) {
    echo "No translation source locale directories found.\n";
    exit(1);
}

foreach ($localeDirectories as $locale => $directory) {
    try {
        merged_locale_catalogue($directory);
    } catch (RuntimeException $error) {
        $hasIssues = true;
        echo $locale, ': ', $error->getMessage(), "\n";
    }
}

$referenceCatalogues = source_catalogues($localeDirectories[$referenceLocale]);

foreach ($localeDirectories as $locale => $directory) {
    $catalogues = source_catalogues($directory);

    foreach (array_keys(array_diff_key($referenceCatalogues, $catalogues)) as $domain) {
        $hasIssues = true;
        echo $locale, ': missing source catalogue ', $domain, ".yaml from reference locale ", $referenceLocale, "\n";
    }

    foreach (array_keys(array_diff_key($catalogues, $referenceCatalogues)) as $domain) {
        $hasIssues = true;
        echo $locale, ': extra source catalogue ', $domain, ".yaml missing from reference locale ", $referenceLocale, "\n";
    }

    foreach (array_intersect(array_keys($referenceCatalogues), array_keys($catalogues)) as $domain) {
        $referenceKeys = flatten((array) Yaml::parseFile($referenceCatalogues[$domain]));
        $localeKeys = flatten((array) Yaml::parseFile($catalogues[$domain]));

        foreach (array_keys(array_diff_key($referenceKeys, $localeKeys)) as $key) {
            $hasIssues = true;
            echo $domain, ': missing ', $locale, ' key ', $key, ' from reference locale ', $referenceLocale, "\n";
        }

        foreach (array_keys(array_diff_key($localeKeys, $referenceKeys)) as $key) {
            $hasIssues = true;
            echo $domain, ': extra ', $locale, ' key ', $key, ' missing from reference locale ', $referenceLocale, "\n";
        }
    }
}

exit($hasIssues ? 1 : 0);
