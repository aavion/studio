<?php

declare(strict_types=1);

namespace App\Core\Workflow;

enum WorkflowStatus: string
{
    case Success = 'success';
    case Invalid = 'invalid';
    case RequiresReview = 'requires_review';
    case Blocked = 'blocked';
    case Failed = 'failed';

    public function isRecoverable(): bool
    {
        return match ($this) {
            self::Invalid, self::RequiresReview, self::Blocked => true,
            self::Success, self::Failed => false,
        };
    }

    public function requiresIssue(): bool
    {
        return match ($this) {
            self::Invalid, self::RequiresReview, self::Blocked, self::Failed => true,
            self::Success => false,
        };
    }
}
