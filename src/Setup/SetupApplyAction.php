<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class SetupApplyAction implements OperationActionInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private SetupWebInputFactory $inputFactory,
        private SetupRunner $setupRunner,
        private array $values,
    ) {
    }

    public function type(): string
    {
        return 'setup_apply';
    }

    public function label(): string
    {
        return 'Apply setup';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::High, [
            '.env.{APP_ENV}.local',
            '.env.local.php',
            'var/',
            'public/assets/',
            'translations/runtime/{APP_ENV}/',
        ]);
    }

    public function execute(): WorkflowResult
    {
        $input = $this->inputFactory->create($this->values);

        if (!$input->isValid() || null === $input->input()) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::E_INVALID_ARGUMENT,
                    MessageKey::OPERATION_INVALID_PAYLOAD,
                    context: ['operation' => $this->type(), 'fields' => array_keys($input->errors())],
                ),
            ], ['errors' => $input->errors()]);
        }

        return $this->setupRunner->run($input->input());
    }
}
