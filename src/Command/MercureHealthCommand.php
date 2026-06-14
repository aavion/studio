<?php

declare(strict_types=1);

namespace App\Command;

use App\View\Alert\MercureAvailability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mercure:health',
    description: 'Check whether the configured Mercure endpoints are reachable.',
)]
final class MercureHealthCommand extends Command
{
    public function __construct(private readonly MercureAvailability $availability)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->availability->refreshStatus();

        if ($status['available']) {
            $output->writeln($status['started']
                ? 'Mercure hub was started and public endpoint is available.'
                : 'Mercure publish and public endpoints are available.');

            return Command::SUCCESS;
        }

        if (!$status['enabled']) {
            $output->writeln('Mercure is disabled; polling fallback remains active.');

            return Command::FAILURE;
        }

        if ($status['publish']) {
            $output->writeln($status['stopped']
                ? 'Mercure publish endpoint is available, but public endpoint is not reachable; hub was stopped and polling fallback remains active.'
                : 'Mercure publish endpoint is available, but public endpoint is not reachable; polling fallback remains active.');

            return Command::FAILURE;
        }

        $output->writeln($status['started']
            ? 'Mercure hub start was requested, but publish endpoint is still not available; polling fallback remains active.'
            : 'Mercure publish endpoint is not available; polling fallback remains active.');

        return Command::FAILURE;
    }
}
