<?php

declare(strict_types=1);

namespace App\Core\Asset;

use App\Core\Asset\AssetMessageCode;
use App\Core\Asset\AssetMessageKey;
use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Message\Message;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Workflow\WorkflowResult;
use Throwable;

final readonly class TailwindBuildAction implements OperationActionInterface
{
    private const MANUAL_COMMAND = 'php bin/console tailwind:build';

    /**
     * @param list<string> $command
     */
    public function __construct(
        private array $command,
        private string $projectDir,
        private ?float $timeout = 300.0,
    ) {
    }

    public function type(): string
    {
        return 'tailwind_build';
    }

    public function label(): string
    {
        return 'Build Tailwind CSS';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Medium, context: [
            'command' => $this->command,
            'manual_command' => self::MANUAL_COMMAND,
            'cwd' => $this->projectDir,
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * @return WorkflowResult<array{tailwind_executed: bool, manual_command: string}>
     */
    public function execute(): WorkflowResult
    {
        try {
            $result = (new RunCommandAction($this->command, $this->projectDir, timeout: $this->timeout, label: $this->label()))->execute();
        } catch (Throwable $error) {
            return $this->deferred([
                'command' => $this->command,
                'cwd' => $this->projectDir,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
        }

        if ($result->isSuccess()) {
            return WorkflowResult::success([
                'tailwind_executed' => true,
                'manual_command' => self::MANUAL_COMMAND,
            ], [
                ...$result->context(),
                'tailwind_executed' => true,
                'manual_command' => self::MANUAL_COMMAND,
            ], $result->messages());
        }

        $context = [
            ...$result->context(),
            'tailwind_executed' => false,
            'manual_command' => self::MANUAL_COMMAND,
        ];

        return WorkflowResult::failed($result->issues(), $context, $result->messages());
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<array{tailwind_executed: bool, manual_command: string}>
     */
    private function deferred(array $context): WorkflowResult
    {
        $context = [
            ...$context,
            'tailwind_executed' => false,
            'manual_command' => self::MANUAL_COMMAND,
        ];

        return WorkflowResult::success([
            'tailwind_executed' => false,
            'manual_command' => self::MANUAL_COMMAND,
        ], $context, [
            Message::warning(AssetMessageCode::TAILWIND_BUILD_DEFERRED, AssetMessageKey::TAILWIND_BUILD_DEFERRED, [
                '%command%' => self::MANUAL_COMMAND,
            ], $context),
        ]);
    }
}
