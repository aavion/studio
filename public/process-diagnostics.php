<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);

$projectDir = dirname(__DIR__);

echo "Studio process diagnostics\n";
echo "Generated: ".gmdate('c')."\n";
echo "SAPI: ".PHP_SAPI."\n";
echo "PHP_VERSION: ".PHP_VERSION."\n";
echo "PHP_BINARY: ".(PHP_BINARY ?: '(empty)')."\n";
echo "Resolved PHP command: ".implode(' ', array_map('escapeshellarg', phpCommand()))."\n";
echo "User: ".userLine()."\n";
echo "Working directory: ".getcwd()."\n";
echo "Project directory: ".$projectDir."\n";
echo "OS: ".php_uname()."\n\n";

section('Process functions');
foreach (['proc_open', 'proc_close', 'proc_get_status', 'proc_terminate', 'shell_exec', 'exec', 'passthru', 'system'] as $function) {
    echo $function.': '.(function_exists($function) && !disabled($function) ? 'available' : 'blocked')."\n";
}
echo 'disable_functions: '.((string) ini_get('disable_functions') ?: '(none)')."\n";
echo 'open_basedir: '.((string) ini_get('open_basedir') ?: '(none)')."\n";
echo 'safe_mode: '.((string) ini_get('safe_mode') ?: '(none)')."\n\n";

section('Selected environment');
foreach (['PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'APP_ENV', 'APP_DEBUG', 'SHELL', 'USER', 'LOGNAME'] as $name) {
    echo $name.': '.safeEnv($name)."\n";
}
echo "\n";

section('/proc/self/status');
echo readProcFile('/proc/self/status');
echo "\n";

section('/proc/self/limits');
echo readProcFile('/proc/self/limits');
echo "\n";

section('/proc/self/cgroup');
echo readProcFile('/proc/self/cgroup');
echo "\n";

section('Relevant mountinfo');
echo relevantMountInfo($projectDir);
echo "\n";

section('Child process smoke test');
printCommandResult('PHP child', [...phpCommand(), '-r', 'echo "child ok\n";']);

$tailwindBinary = latestTailwindBinary($projectDir);
if (null === $tailwindBinary) {
    echo "Tailwind binary: not found under var/tailwind\n";
} else {
    echo "Tailwind binary: ".$tailwindBinary."\n";
    echo "Tailwind executable: ".(is_executable($tailwindBinary) ? 'yes' : 'no')."\n";
    printCommandResult('Tailwind help', [$tailwindBinary, '--help']);
    printCommandResult('Tailwind build smoke', [
        $tailwindBinary,
        '-i',
        $projectDir.'/assets/styles/app.css',
        '-o',
        $projectDir.'/var/tailwind/diagnostics.built.css',
    ]);
}

function section(string $title): void
{
    echo "== ".$title." ==\n";
}

function disabled(string $function): bool
{
    $disabled = array_filter(array_map('trim', explode(',', strtolower((string) ini_get('disable_functions')))));

    return in_array(strtolower($function), $disabled, true);
}

function safeEnv(string $name): string
{
    $value = getenv($name);
    if (false === $value) {
        return '(unset)';
    }

    if (preg_match('/SECRET|TOKEN|PASSWORD|PASS|KEY|DSN|DATABASE|COOKIE|AUTH/i', $name)) {
        return '[redacted]';
    }

    return $value;
}

function userLine(): string
{
    $uid = function_exists('posix_getuid') ? (string) posix_getuid() : 'unknown';
    $euid = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'unknown';
    $name = 'unknown';

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            $name = (string) $info['name'];
        }
    }

    return sprintf('%s (uid=%s, euid=%s)', $name, $uid, $euid);
}

function readProcFile(string $path): string
{
    if (!is_readable($path)) {
        return $path.": not readable\n";
    }

    $contents = file_get_contents($path);

    return false === $contents ? $path.": read failed\n" : $contents;
}

/**
 * @return list<string>
 */
function phpCommand(): array
{
    $candidates = [];
    if ('' !== trim(PHP_BINARY)) {
        $candidates[] = [PHP_BINARY];
    }

    if ('' !== trim(PHP_BINDIR)) {
        $candidates[] = [rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.phpExecutableName()];
    }

    $candidates[] = [phpExecutableName()];

    if ('\\' !== DIRECTORY_SEPARATOR) {
        $candidates[] = ['/usr/bin/env', 'php'];
        $candidates[] = ['/usr/bin/php'];
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $signature = implode("\0", $candidate);
        if (isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        if (commandWorks([...$candidate, '-r', 'exit(PHP_SAPI === "cli" ? 0 : 1);'])) {
            return $candidate;
        }
    }

    return ['php'];
}

function phpExecutableName(): string
{
    return '\\' === DIRECTORY_SEPARATOR ? 'php.exe' : 'php';
}

/**
 * @param list<string> $command
 */
function commandWorks(array $command): bool
{
    if (!function_exists('proc_open') || disabled('proc_open')) {
        return false;
    }

    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));

    if (!is_resource($process)) {
        return false;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return 0 === proc_close($process);
}

function relevantMountInfo(string $projectDir): string
{
    $contents = readProcFile('/proc/self/mountinfo');
    if (str_contains($contents, 'not readable') || str_contains($contents, 'read failed')) {
        return $contents;
    }

    $targets = array_filter([
        '/',
        '/tmp',
        '/var',
        '/var/tmp',
        realpath($projectDir) ?: $projectDir,
    ]);
    $lines = [];

    foreach (explode("\n", $contents) as $line) {
        foreach ($targets as $target) {
            if (str_contains($line, ' '.$target.' ')) {
                $lines[$line] = $line;
            }
        }
    }

    return [] === $lines ? "No matching mountinfo lines found.\n" : implode("\n", $lines)."\n";
}

/**
 * @param list<string> $command
 */
function printCommandResult(string $label, array $command): void
{
    echo $label.": ".implode(' ', array_map('escapeshellarg', $command))."\n";

    if (!function_exists('proc_open') || disabled('proc_open')) {
        echo "  proc_open blocked\n";
        return;
    }

    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));

    if (!is_resource($process)) {
        echo "  start failed\n";
        return;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    echo "  exit_code: ".$exitCode."\n";
    echo "  stdout: ".excerpt($stdout)."\n";
    echo "  stderr: ".excerpt($stderr)."\n";
}

function excerpt(string|false $value): string
{
    $value = false === $value ? '' : trim($value);
    if ('' === $value) {
        return '(empty)';
    }

    return strlen($value) > 2000 ? substr($value, 0, 2000)."\n  [... truncated ...]" : $value;
}

function latestTailwindBinary(string $projectDir): ?string
{
    $base = $projectDir.'/var/tailwind';
    if (!is_dir($base)) {
        return null;
    }

    $matches = glob($base.'/*/tailwindcss-*') ?: [];
    rsort($matches);

    foreach ($matches as $match) {
        if (is_file($match)) {
            return $match;
        }
    }

    return null;
}
