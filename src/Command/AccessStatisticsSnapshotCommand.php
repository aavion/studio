<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Statistics\AccessStatisticsPolicy;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Core\Statistics\AccessStatisticsWindow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'studio:statistics:snapshot',
    description: 'Refresh the stored access statistics snapshot.',
)]
final class AccessStatisticsSnapshotCommand extends Command
{
    public function __construct(
        private readonly AccessStatisticsSnapshotProvider $snapshotProvider,
        private readonly AccessStatisticsPolicy $policy,
        private readonly AccessStatisticsWindow $window,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON output.')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Statistics window to refresh.', AccessStatisticsWindow::DEFAULT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = $this->window->normalize($input->getOption('window'));

        if (!$this->policy->isDisplayEnabled()) {
            return $this->writeResult($output, (bool) $input->getOption('json'), [
                'status' => 'skipped',
                'reason' => 'statistics_disabled',
                'window' => $window,
            ]);
        }

        $snapshotResult = $this->snapshotProvider->snapshotWithStorageStatus($window);
        $snapshot = $snapshotResult['snapshot'];

        if (!$snapshotResult['stored']) {
            return $this->writeResult($output, (bool) $input->getOption('json'), [
                'status' => 'failed',
                'reason' => 'snapshot_store_failed',
                'window' => $snapshot['window'] ?? $window,
                'total_requests' => $snapshot['total_requests'] ?? 0,
                'generated_at' => $snapshot['generated_at'] ?? null,
            ]);
        }

        return $this->writeResult($output, (bool) $input->getOption('json'), [
            'status' => 'success',
            'window' => $snapshot['window'] ?? $window,
            'total_requests' => $snapshot['total_requests'] ?? 0,
            'generated_at' => $snapshot['generated_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeResult(OutputInterface $output, bool $json, array $payload): int
    {
        $exitCode = 'failed' === $payload['status'] ? Command::FAILURE : Command::SUCCESS;

        if ($json) {
            $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        if ('skipped' === $payload['status']) {
            $output->writeln(sprintf('Statistics snapshot skipped for "%s": statistics are disabled.', $payload['window']));

            return $exitCode;
        }

        if ('failed' === $payload['status']) {
            $output->writeln(sprintf('Statistics snapshot failed for "%s": %s.', $payload['window'], $payload['reason'] ?? 'unknown'));

            return $exitCode;
        }

        $output->writeln(sprintf(
            'Statistics snapshot refreshed for "%s" with %d request(s).',
            $payload['window'],
            $payload['total_requests'],
        ));

        return $exitCode;
    }
}
