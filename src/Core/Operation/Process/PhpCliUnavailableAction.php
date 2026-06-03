<?php

declare(strict_types=1);

namespace App\Core\Operation\Process;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class PhpCliUnavailableAction implements OperationActionInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $subject,
        private string $reason,
        private array $context = [],
    ) {
    }

    public function type(): string
    {
        return 'php_cli_unavailable';
    }

    public function label(): string
    {
        return 'Resolve PHP CLI for '.$this->subject;
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Medium, context: $this->context());
    }

    /**
     * @return WorkflowResult<null>
     */
    public function execute(): WorkflowResult
    {
        return WorkflowResult::failed([
            self::message($this->subject, $this->reason, $this->context),
        ], $this->context());
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function message(string $subject, string $reason, array $context = []): Message
    {
        return Message::error(
            MessageCode::PROCESS_PHP_CLI_UNAVAILABLE,
            MessageKey::PROCESS_PHP_CLI_UNAVAILABLE,
            ['%command%' => $subject],
            [
                ...$context,
                'command' => $subject,
                'php_cli_reason' => $reason,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            ...$this->context,
            'command' => $this->subject,
            'php_cli_reason' => $this->reason,
        ];
    }
}
