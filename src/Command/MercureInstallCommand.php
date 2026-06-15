<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Mercure\MercureBinaryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mercure:install',
    description: 'Install the optional local Mercure hub binary for this platform.',
)]
final class MercureInstallCommand extends Command
{
    public function __construct(private readonly MercureBinaryManager $binaryManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->binaryManager->install()) {
            $output->writeln(sprintf('Mercure binary is available at %s', $this->binaryManager->binaryPath()));

            return Command::SUCCESS;
        }

        $output->writeln('Mercure binary is not available for this platform or could not be installed.');

        return Command::FAILURE;
    }
}
