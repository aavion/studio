#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

if ($help) {
    echo <<<'HELP'
Usage:
  php .codex/clean_ignored_artifacts.php [--apply]

By default this performs a dry-run using git clean and only targets ignored
files.

Examples:
  php .codex/clean_ignored_artifacts.php
  php .codex/clean_ignored_artifacts.php --apply

HELP;
    exit(0);
}

$command = ['git', 'clean', '-X', '-d', $apply ? '-f' : '-n'];
$output = [];
$exitCode = run($command, $root, $output);

if (0 !== $exitCode) {
    foreach ($output as $line) {
        echo $line.PHP_EOL;
    }

    exit($exitCode);
}

if ([] === $output) {
    echo "[OK] No ignored artifacts found.\n";
} else {
    foreach ($output as $line) {
        echo ($apply ? '[DELETE] ' : '[DRY-RUN] ').$line.PHP_EOL;
    }
}

if (!$apply && [] !== $output) {
    echo "[INFO] Re-run with --apply to delete listed ignored artifacts.\n";
}

exit(0);

/**
 * @param list<string> $command
 * @param list<string> $output
 */
function run(array $command, string $cwd, array &$output): int
{
    $process = proc_open(
        array_map('strval', $command),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd,
    );

    if (!is_resource($process)) {
        $output[] = 'Failed to start cleanup process.';

        return 1;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $lines = array_filter(explode(PHP_EOL, trim((string) $stdout.PHP_EOL.(string) $stderr)));
    $output = array_values($lines);

    return $exitCode;
}
