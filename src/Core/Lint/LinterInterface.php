<?php

declare(strict_types=1);

namespace App\Core\Lint;

interface LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult;
}
