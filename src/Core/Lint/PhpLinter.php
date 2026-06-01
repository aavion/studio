<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use Symfony\Component\Process\Process;

final class PhpLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'studio-php-lint-');

        if (false === $temporaryPath || false === file_put_contents($temporaryPath, $contents)) {
            return LintResult::invalid([
                LintIssue::create(
                    MessageCode::LINT_PHP_UNREADABLE,
                    MessageKey::LINT_PHP_UNREADABLE,
                    details: ['path' => $path],
                ),
            ]);
        }

        $process = new Process([PHP_BINARY, '-l', $temporaryPath], timeout: 10.0);
        $process->run();
        unlink($temporaryPath);

        if (!$process->isSuccessful()) {
            $output = trim($process->getOutput().PHP_EOL.$process->getErrorOutput());

            return LintResult::invalid([
                LintIssue::create(
                    MessageCode::LINT_PHP_SYNTAX_ERROR,
                    MessageKey::LINT_PHP_SYNTAX_ERROR,
                    details: ['error' => $output, 'output' => $output, 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
