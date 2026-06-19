<?php

declare(strict_types=1);

namespace App\Command;

use App\Backend\ExtensionLifecycleAdmin;
use App\Core\Console\ConsoleResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extensions:lifecycle',
    description: 'Apply an extension lifecycle action.',
)]
final class ExtensionLifecycleCommand extends Command
{
    public function __construct(
        private readonly ExtensionLifecycleAdmin $extensionLifecycleAdmin,
        private readonly ConsoleResultRenderer $resultRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('extension', InputArgument::REQUIRED, 'The extension identifier.')
            ->addArgument('action', InputArgument::REQUIRED, 'The lifecycle action.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = (string) $input->getArgument('extension');
        $action = (string) $input->getArgument('action');
        $result = $this->extensionLifecycleAdmin->apply($extensionName, $action);

        return $this->resultRenderer->writeWorkflow($output, $result);
    }
}
