<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\AclGroupApplyService;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'studio:acl-groups:apply',
    description: 'Apply a reviewed ACL group update or delete operation.',
)]
final class AclGroupApplyCommand extends Command
{
    public function __construct(private readonly AclGroupApplyService $applyService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('group-uid', InputArgument::REQUIRED, 'The ACL group UID.')
            ->addArgument('action', InputArgument::REQUIRED, 'The ACL group action.')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'Base64-encoded JSON payload for the action.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->applyService->apply(
            (string) $input->getArgument('group-uid'),
            (string) $input->getArgument('action'),
            $this->payload((string) ($input->getOption('payload') ?? '')),
        );

        foreach ($result->issues() as $issue) {
            $output->writeln(sprintf('[%s] %s', $issue->level()->value, $issue->translationKey()));
        }

        foreach ($result->messages() as $message) {
            $output->writeln(sprintf('[%s] %s', $message->level()->value, $message->translationKey()));
        }

        return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $encodedPayload): array
    {
        if ('' === $encodedPayload) {
            return [];
        }

        $json = base64_decode($encodedPayload, true);

        if (!is_string($json)) {
            return [];
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
