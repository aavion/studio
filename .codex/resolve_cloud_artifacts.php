#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$preferBase = in_array('--prefer-base', $argv, true);

$excludedDirectories = [
    '.git',
    'assets/vendor',
    'var',
    'vendor',
];

$actions = [];
$warnings = [];

foreach (scan($root, $excludedDirectories) as $path) {
    $relativePath = relativePath($root, $path);
    $basename = basename($path);

    if ('.DS_Store' === $basename) {
        $actions[] = deleteAction('os-artifact', $relativePath, $path, 'macOS metadata file');
        continue;
    }

    $basePath = basePathForCloudCopy($path);
    if (null === $basePath || !is_file($basePath)) {
        continue;
    }

    $baseRelativePath = relativePath($root, $basePath);
    $isIdentical = hash_file('sha256', $path) === hash_file('sha256', $basePath);

    if ($isIdentical) {
        $actions[] = deleteAction('duplicate-identical', $relativePath, $path, sprintf('same contents as %s', $baseRelativePath));
        continue;
    }

    if ($preferBase) {
        $actions[] = deleteAction('duplicate-prefer-base', $relativePath, $path, sprintf('differs from %s; keeping base because --prefer-base was supplied', $baseRelativePath));
        continue;
    }

    $warnings[] = sprintf(
        'Manual review required: %s differs from %s. Re-run with --apply --prefer-base to keep the base file and delete the copy.',
        $relativePath,
        $baseRelativePath,
    );
}

if ([] === $actions && [] === $warnings) {
    echo "[OK] No cloud conflict artifacts found.\n";
    exit(0);
}

foreach ($actions as $action) {
    printf(
        "[%s] %s %s (%s)\n",
        $apply ? 'DELETE' : 'DRY-RUN',
        $action['kind'],
        $action['relative_path'],
        $action['reason'],
    );

    if ($apply && !unlink($action['path'])) {
        $warnings[] = sprintf('Failed to delete %s.', $action['relative_path']);
    }
}

foreach ($warnings as $warning) {
    echo "[WARN] ".$warning."\n";
}

if (!$apply && [] !== $actions) {
    echo "[INFO] Re-run with --apply to delete safe artifacts.\n";
}

exit([] === $warnings ? 0 : 1);

/**
 * @param list<string> $excludedDirectories
 *
 * @return Generator<int, string>
 */
function scan(string $root, array $excludedDirectories): Generator
{
    $items = scandir($root);
    if (false === $items) {
        return;
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $path = $root.DIRECTORY_SEPARATOR.$item;
        $relativePath = relativePath(dirname(__DIR__), $path);

        if (is_dir($path)) {
            if (in_array($relativePath, $excludedDirectories, true)) {
                continue;
            }

            yield from scan($path, $excludedDirectories);
            continue;
        }

        yield $path;
    }
}

function relativePath(string $root, string $path): string
{
    return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
}

function basePathForCloudCopy(string $path): ?string
{
    $directory = dirname($path);
    $basename = basename($path);

    if (!preg_match('/^(?<base>.+) (?<copy>[2-9][0-9]*)(?<extension>\.[^.]*)?$/', $basename, $matches)) {
        return null;
    }

    $baseName = $matches['base'].($matches['extension'] ?? '');

    return $directory.DIRECTORY_SEPARATOR.$baseName;
}

/**
 * @return array{kind: string, relative_path: string, path: string, reason: string}
 */
function deleteAction(string $kind, string $relativePath, string $path, string $reason): array
{
    return [
        'kind' => $kind,
        'relative_path' => $relativePath,
        'path' => $path,
        'reason' => $reason,
    ];
}
