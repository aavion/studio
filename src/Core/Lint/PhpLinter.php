<?php

declare(strict_types=1);

namespace App\Core\Lint;

final class PhpLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'studio-php-lint-');

        if (false === $temporaryPath || false === file_put_contents($temporaryPath, $contents)) {
            return LintResult::invalid([
                LintIssue::create(
                    'lint.php_unreadable',
                    'PHP could not be written to a temporary lint file.',
                    details: ['path' => $path],
                ),
            ]);
        }

        $command = [PHP_BINARY, '-l', $temporaryPath];
        $output = [];
        $exitCode = 1;

        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>&1', $output, $exitCode);
        unlink($temporaryPath);

        if (0 !== $exitCode) {
            return LintResult::invalid([
                LintIssue::create(
                    'lint.php_syntax_error',
                    'PHP has a syntax error.',
                    details: ['error' => implode(PHP_EOL, $output), 'output' => implode(PHP_EOL, $output), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
