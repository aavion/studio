<?php

declare(strict_types=1);

namespace App\Command;

use App\Backend\PackageLifecycleAdmin;
use App\Core\Console\ConsoleWorkflowResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'studio:packages:lifecycle',
    description: 'Apply a package lifecycle action.',
)]
final class PackageLifecycleCommand extends Command
{
    public function __construct(
        private readonly PackageLifecycleAdmin $packageLifecycleAdmin,
        private readonly ConsoleWorkflowResultRenderer $resultRenderer,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'The package identifier.')
            ->addArgument('action', InputArgument::REQUIRED, 'The lifecycle action.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageName = (string) $input->getArgument('package');
        $action = (string) $input->getArgument('action');
        $result = $this->packageLifecycleAdmin->apply($packageName, $action);

        return $this->resultRenderer->write($output, $result);
    }
}
