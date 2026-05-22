<?php

declare(strict_types=1);

namespace App\Core\Lint;

use InvalidArgumentException;

final readonly class LintResult
{
    /**
     * @param list<LintIssue> $issues
     */
    private function __construct(
        private array $issues = [],
    ) {
        foreach ($issues as $issue) {
            if (!$issue instanceof LintIssue) {
                throw new InvalidArgumentException('Lint results may only contain lint issues.');
            }
        }
    }

    public static function success(): self
    {
        return new self();
    }

    /**
     * @param list<LintIssue> $issues
     */
    public static function invalid(array $issues): self
    {
        if ([] === $issues) {
            throw new InvalidArgumentException('Invalid lint results must contain at least one issue.');
        }

        return new self($issues);
    }

    public function isSuccess(): bool
    {
        return [] === $this->issues;
    }

    /**
     * @return list<LintIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function firstIssue(): ?LintIssue
    {
        return $this->issues[0] ?? null;
    }
}
