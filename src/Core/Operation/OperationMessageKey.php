<?php

declare(strict_types=1);

namespace App\Core\Operation;

final class OperationMessageKey
{
    public const OPERATION_EXCEPTION = 'message.operation.exception';
    public const OPERATION_UNKNOWN = 'message.operation.unknown';
    public const OPERATION_STARTED = 'message.operation.started';
    public const OPERATION_START_FAILED = 'message.operation.start_failed';
    public const OPERATION_RUNNER_START_FAILED = 'message.operation.runner_start_failed';
    public const OPERATION_PHP_CLI_UNAVAILABLE = 'message.operation.php_cli_unavailable';
    public const OPERATION_INVALID_PAYLOAD = 'message.operation.invalid_payload';
    public const OPERATION_STALE = 'message.operation.stale';
    public const OPERATION_LOCKED = 'message.operation.locked';
    public const OPERATION_ACTION_REQUIRED = 'message.operation.action_required';
    public const OPERATION_FINISHED = 'message.operation.finished';
    public const OPERATION_REQUIRES_REVIEW = 'message.operation.requires_review';
    public const OPERATION_FAILED = 'message.operation.failed';
    public const OPERATION_FINISHED_UNKNOWN = 'message.operation.finished_unknown';
}
