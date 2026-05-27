<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Message\Message;
use App\Core\Message\MessageKey;
use App\Core\Package\PackageDiscoveryDispatcher;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'studio:packages:discover',
    description: 'Discover packages, validate them, and synchronize the package registry.',
)]
final class PackageDiscoveryCommand extends Command
{
    public function __construct(
        private readonly PackageDiscoveryDispatcher $dispatcher,
        private readonly PackageDiscoveryRunner $runner,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON output.')
            ->addOption('run-now', null, InputOption::VALUE_NONE, 'Run discovery synchronously instead of queuing it.')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Record the lifecycle trigger name.', 'manual');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $runNow = (bool) $input->getOption('run-now');
        $result = $runNow
            ? ($this->runner)((string) $input->getOption('trigger'))
            : $this->dispatcher->dispatch((string) $input->getOption('trigger'));

        if ($json) {
            $output->writeln($this->json($result->toArray()));

            return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Package discovery');

        $this->writeMessages($io, $result);

        if ($runNow && $result->isSuccess()) {
            $value = $result->value() ?? ['candidate_count' => 0, 'change_count' => 0, 'changes' => []];

            $io->writeln(sprintf('Discovered candidates: %d', $value['candidate_count']));
            $io->writeln(sprintf('Registry changes: %d', $value['change_count']));
            $this->writeChanges($io, $value['changes']);

            return Command::SUCCESS;
        }

        if ($result->isSuccess()) {
            return Command::SUCCESS;
        }

        $io->error($this->formatFailure($result));

        return Command::FAILURE;
    }

    /**
     * @param list<array{package: string, action: string, status: string}> $changes
     */
    private function writeChanges(SymfonyStyle $io, array $changes): void
    {
        if ([] === $changes) {
            return;
        }

        $io->table(['Package', 'Action', 'Status'], array_map(
            static fn (array $change): array => [$change['package'], $change['action'], $change['status']],
            $changes,
        ));
    }

    private function formatIssue(Message $issue): string
    {
        return sprintf('%s: %s', $issue->code(), $this->translator->trans($issue->translationKey(), $issue->parameters()));
    }

    private function translate(?Message $message, string $fallback): string
    {
        if (null === $message) {
            return $fallback;
        }

        return $this->translator->trans($message->translationKey(), $message->parameters());
    }

    private function formatFailure(WorkflowResult $result): string
    {
        $issue = $result->firstIssue();

        if (null === $issue) {
            return $this->translator->trans(MessageKey::OPERATION_EXCEPTION);
        }

        return $this->formatIssue($issue);
    }

    private function writeMessages(SymfonyStyle $io, WorkflowResult $result): void
    {
        foreach ($result->messages() as $message) {
            $io->writeln($this->translate($message, $message->translationKey()));
        }

        foreach ($result->issues() as $issue) {
            $io->warning($this->formatIssue($issue));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
