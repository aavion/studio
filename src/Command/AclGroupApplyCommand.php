<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Console\ConsoleResultRenderer;
use App\Security\AclGroupApplyService;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'acl-groups:apply',
    description: 'Apply a reviewed ACL group update or delete operation.',
)]
final class AclGroupApplyCommand extends Command
{
    public function __construct(
        private readonly AclGroupApplyService $applyService,
        private readonly ConsoleResultRenderer $resultRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('group-uid', InputArgument::REQUIRED, 'The ACL group UID.')
            ->addArgument('action', InputArgument::REQUIRED, 'The ACL group action.')
            ->addOption('actor-uid', null, InputOption::VALUE_REQUIRED, 'The admin user UID that confirmed the ACL group action.')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'Base64-encoded JSON payload for the action.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->applyService->apply(
            (string) $input->getArgument('group-uid'),
            (string) $input->getArgument('action'),
            (string) ($input->getOption('actor-uid') ?? ''),
            $this->payload((string) ($input->getOption('payload') ?? '')),
        );

        return $this->resultRenderer->writeWorkflow($output, $result);
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
