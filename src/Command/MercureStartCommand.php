<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Mercure\MercureRuntime;
use App\Core\Process\DetachedProcessStarter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mercure:start',
    description: 'Start the optional local Mercure hub binary in the background.',
)]
final class MercureStartCommand extends Command
{
    public function __construct(
        private readonly MercureRuntime $runtime,
        private readonly DetachedProcessStarter $starter,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->runtime->canStart()) {
            $output->writeln('Mercure binary is not available; polling fallback remains active.');

            return Command::FAILURE;
        }

        $started = $this->starter->start(
            $this->runtime->startCommand(),
            $this->projectDir,
            $this->runtime->logPath(),
            $this->runtime->pidPath(),
        );

        $output->writeln($started ? 'Mercure hub start was requested.' : 'Mercure hub could not be started.');

        return $started ? Command::SUCCESS : Command::FAILURE;
    }
}
