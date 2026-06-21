<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Console\ConsoleResultRenderer;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\OperationExecutor;
use App\Core\Extension\ActiveExtensionAssetProviderInterface;
use App\Core\Extension\ExtensionAssetRebuildDispatcher;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

#[AsCommand(
    name: 'assets:rebuild',
    description: 'Run the full application asset rebuild pipeline.',
)]
final class AssetRebuildCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ActiveExtensionAssetProviderInterface $extensionProvider,
        private readonly AssetRebuildQueueFactory $queueFactory,
        private readonly OperationExecutor $operationExecutor,
        private readonly ExtensionAssetRebuildDispatcher $rebuildDispatcher,
        private readonly ConsoleResultRenderer $resultRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the rebuild plan without writing files.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON output.')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Queue the rebuild through Messenger instead of running it now.')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Record the lifecycle trigger name.', 'manual');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $queue = (bool) $input->getOption('queue');
        $trigger = (string) $input->getOption('trigger');
        $extensionProviderError = null;

        if ($queue) {
            $result = $this->rebuildDispatcher->dispatch($this->kernel->getEnvironment(), $trigger);

            if ($json) {
                $this->resultRenderer->writeWorkflow($output, $result, true);
            } elseif ($result->isSuccess()) {
                $io->success('Asset rebuild queued.');
            } else {
                $io->error('Asset rebuild could not be queued.');
            }

            return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
        }

        try {
            $extensions = $this->extensionProvider->extensions();
        } catch (Throwable $error) {
            if (!$dryRun) {
                $this->writeProviderFailure($io, $output, $json, $error);

                return Command::FAILURE;
            }

            $extensions = [];
            $extensionProviderError = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ];
        }

        $queue = $this->queueFactory->create($this->kernel->getEnvironment(), $extensions, $trigger, !$dryRun);

        if ($dryRun) {
            $plan = $this->operationExecutor->planQueue($queue);
            $payload = $plan->toArray();

            if (null !== $extensionProviderError) {
                $payload['context']['extension_provider_error'] = $extensionProviderError;
            }

            if ($json) {
                $this->resultRenderer->writeJsonPayload($output, $payload, true);
            } else {
                $io->title('Asset rebuild dry-run');
                if (null !== $extensionProviderError) {
                    $io->warning('Active extensions could not be loaded. The dry-run plan assumes no active extensions.');
                }

                $this->writeDryRun($io, $payload['actions']);
            }

            return Command::SUCCESS;
        }

        $execution = $this->operationExecutor->executeQueue(
            $queue,
            $json ? null : $this->entryWriter($io),
            $json ? null : $this->startWriter($io),
        );

        if ($json) {
            $this->resultRenderer->writeJsonPayload($output, $execution->toArray(), true);
        } elseif ($execution->result()->isSuccess()) {
            $io->success('Asset rebuild completed.');
        } else {
            $io->error('Asset rebuild failed.');
        }

        return $execution->result()->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function writeDryRun(SymfonyStyle $io, array $actions): void
    {
        foreach ($actions as $index => $action) {
            $io->writeln(sprintf('[%d/%d] %s', $index + 1, count($actions), $action['label']));
        }
    }

    private function startWriter(SymfonyStyle $io): callable
    {
        return static function (ActionLogEntry $entry, int $index, int $total, OperationActionInterface $action) use ($io): void {
            $io->writeln(sprintf('[%d/%d] %s', $index, $total, $entry->name()));
        };
    }

    private function entryWriter(SymfonyStyle $io): callable
    {
        return static function (ActionLogEntry $entry, int $index, int $total, WorkflowResult $result) use ($io): void {
            foreach ($entry->issues() as $issue) {
                $io->warning(sprintf('%s: %s', $issue->code(), $issue->translationKey()));
            }

            foreach ($entry->messages() as $message) {
                if (!self::isTextModeWarning($message)) {
                    continue;
                }

                $io->warning(sprintf('%s: %s', $message->code(), $message->translationKey()));
            }
        };
    }

    private static function isTextModeWarning(Message $message): bool
    {
        return in_array($message->level(), [
            MessageLevel::Exception,
            MessageLevel::Error,
            MessageLevel::Warning,
        ], true);
    }

    private function writeProviderFailure(SymfonyStyle $io, OutputInterface $output, bool $json, Throwable $error): void
    {
        $payload = [
            'status' => 'failed',
            'error' => [
                'code' => 'extension.asset_provider_failed',
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ],
        ];

        if ($json) {
            $this->resultRenderer->writeJsonPayload($output, $payload, true);
            return;
        }

        $io->error('Active extensions could not be loaded; asset rebuild was not started.');
    }
}
