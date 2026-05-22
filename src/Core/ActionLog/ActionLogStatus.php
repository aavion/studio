<?php

declare(strict_types=1);

namespace App\Core\ActionLog;

enum ActionLogStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Running => false,
            self::Success, self::Warning, self::Failed, self::Skipped => true,
        };
    }
}
