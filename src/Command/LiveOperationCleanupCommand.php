<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Operation\Live\LiveOperationRunStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'operations:cleanup',
    description: 'Remove expired live operation run files.',
)]
final class LiveOperationCleanupCommand extends Command
{
    public function __construct(private readonly LiveOperationRunStore $runStore)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Time to keep terminal or stale runs in seconds.', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ttl = max(0, (int) $input->getOption('ttl'));
        $result = $this->runStore->cleanup($ttl);

        $output->writeln(sprintf('Checked %d run(s), removed %d expired run(s).', $result['checked'], $result['removed']));

        return Command::SUCCESS;
    }
}
