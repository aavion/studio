<?php

declare(strict_types=1);

namespace App\Core\Operation\Process;

final class ProcessMessageCode
{
    public const PROCESS_COMMAND_FAILED = 'process.command_failed';
    public const PROCESS_COMMAND_COMPLETED = 'process.command_completed';
    public const PROCESS_PHP_CLI_UNAVAILABLE = 'process.php_cli_unavailable';
}
