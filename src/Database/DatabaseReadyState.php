<?php

declare(strict_types=1);

namespace App\Database;

use App\Setup\SetupCompletionMarker;

final readonly class DatabaseReadyState
{
    public const ALLOW_UNREADY_KEY = 'APP_DATABASE_ALLOW_UNREADY';

    public function __construct(
        private SetupCompletionMarker $completionMarker,
        private string $projectDir,
        private string $environment,
    ) {
    }

    public function isReady(): bool
    {
        if ($this->truthy($_SERVER[self::ALLOW_UNREADY_KEY] ?? $_ENV[self::ALLOW_UNREADY_KEY] ?? getenv(self::ALLOW_UNREADY_KEY))) {
            return true;
        }

        return $this->completionMarker->isComplete($this->projectDir, $this->environment);
    }

    private function truthy(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value, " \t\n\r\0\x0B'\"")), ['1', 'true', 'yes'], true);
    }
}
