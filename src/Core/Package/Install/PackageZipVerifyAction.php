<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class PackageZipVerifyAction implements OperationActionInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private PackageZipInstaller $installer,
        private array $payload,
    ) {
    }

    public function type(): string
    {
        return 'package.zip.verify';
    }

    public function label(): string
    {
        return 'Verify package ZIP';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Low, context: $this->payload);
    }

    /**
     * @return WorkflowResult<mixed>
     */
    public function execute(): WorkflowResult
    {
        return $this->installer->verify($this->payload);
    }
}
