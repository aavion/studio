<?php

declare(strict_types=1);

namespace App\Core\Process;

use Symfony\Component\Process\Process;
use Throwable;

final readonly class PhpCliBinaryValidator
{
    public function __construct(private PhpProjectRequirements $requirements = new PhpProjectRequirements())
    {
    }

    /**
     * @param list<string> $commandPrefix
     * @param array<string, string|false> $environment
     */
    public function validate(array $commandPrefix, string $projectDir, array $environment = []): PhpCliBinaryValidationResult
    {
        if ([] === $commandPrefix) {
            return PhpCliBinaryValidationResult::invalid('binary_not_found');
        }

        $minimumVersion = $this->requirements->minimumPhpVersion($projectDir);
        $requiredExtensions = $this->requirements->requiredPhpExtensions($projectDir);

        try {
            $process = new Process(
                [
                    ...$commandPrefix,
                    '-r',
                    $this->validationScript(),
                    $projectDir,
                    $minimumVersion ?? '',
                    implode(',', $requiredExtensions),
                ],
                $projectDir,
                CliProcessEnvironment::fromCurrentProcess($environment),
                timeout: 10.0,
            );
            $process->run();
        } catch (Throwable $error) {
            return PhpCliBinaryValidationResult::invalid('process_failed', [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
        }

        $context = [
            'command_prefix' => $commandPrefix,
            'project_dir' => $projectDir,
            'minimum_php_version' => $minimumVersion,
            'required_extensions' => $requiredExtensions,
            'exit_code' => $process->getExitCode(),
            'output' => trim($process->getOutput()),
            'error_output' => trim($process->getErrorOutput()),
        ];

        if ($process->isSuccessful()) {
            return PhpCliBinaryValidationResult::valid($context);
        }

        $reason = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'validation_failed';

        return PhpCliBinaryValidationResult::invalid($this->normalizeReason($reason), $context);
    }

    private function validationScript(): string
    {
        return <<<'PHP'
$fail = static function (string $reason): void {
    fwrite(STDERR, $reason);
    exit(10);
};

$projectDir = $argv[1] ?? '';
$minimumVersion = $argv[2] ?? '';
$requiredExtensions = array_values(array_filter(explode(',', $argv[3] ?? ''), static fn (string $extension): bool => '' !== trim($extension)));

if (PHP_SAPI !== 'cli') {
    $fail('not_cli');
}

if ($minimumVersion !== '' && version_compare(PHP_VERSION, $minimumVersion, '<')) {
    $fail('php_version_too_old');
}

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $fail('extension_missing:'.$extension);
    }
}

if ($projectDir === '' || !is_dir($projectDir) || !is_readable($projectDir)) {
    $fail('project_dir_unreadable');
}

$console = rtrim($projectDir, DIRECTORY_SEPARATOR.'/\\').DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console';
if (!is_file($console) || !is_readable($console)) {
    $fail('console_unreadable');
}

echo PHP_VERSION;
PHP;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);

        if (str_starts_with($reason, 'extension_missing:')) {
            return 'extension_missing';
        }

        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $reason) ?: 'validation_failed';
    }
}
