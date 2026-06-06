<?php

declare(strict_types=1);

namespace App\Core\Operation\Process;

final class ProcessMessageKey
{
    public const PROCESS_COMMAND_FAILED = 'message.process.command_failed';
    public const PROCESS_COMMAND_COMPLETED = 'message.process.command_completed';
    public const PROCESS_PHP_CLI_UNAVAILABLE = 'message.process.php_cli_unavailable';
}
