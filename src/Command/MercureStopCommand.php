<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Mercure\MercureRuntime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mercure:stop',
    description: 'Stop the optional local Mercure hub process.',
)]
final class MercureStopCommand extends Command
{
    public function __construct(private readonly MercureRuntime $runtime)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runtime->stop()) {
            $output->writeln('Mercure hub stop was requested.');

            return Command::SUCCESS;
        }

        $output->writeln('Mercure hub could not be stopped.');

        return Command::FAILURE;
    }
}
