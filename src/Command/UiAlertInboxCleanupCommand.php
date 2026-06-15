<?php

declare(strict_types=1);

namespace App\Command;

use App\View\Alert\UiAlertInbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'ui-alerts:cleanup-inbox',
    description: 'Remove expired queued UI alerts from the polling inbox.',
)]
final class UiAlertInboxCleanupCommand extends Command
{
    public function __construct(private readonly UiAlertInbox $inbox)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $removed = $this->inbox->cleanupExpired();
        } catch (Throwable $exception) {
            $output->writeln(sprintf('UI alert inbox cleanup failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('UI alert inbox cleanup removed %d expired row(s).', $removed));

        return Command::SUCCESS;
    }
}
