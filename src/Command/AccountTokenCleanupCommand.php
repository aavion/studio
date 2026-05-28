<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccountToken;
use App\Security\AccountTokenStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'studio:account-tokens:cleanup',
    description: 'Remove expired account tokens.',
)]
final class AccountTokenCleanupCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('include-used', null, InputOption::VALUE_NONE, 'Also remove expired tokens that were already consumed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statuses = [
            AccountTokenStatus::Pending,
            AccountTokenStatus::PendingApproval,
            AccountTokenStatus::Revoked,
        ];

        if (true === $input->getOption('include-used')) {
            $statuses[] = AccountTokenStatus::Used;
        }

        $removed = $this->entityManager->createQueryBuilder()
            ->delete(AccountToken::class, 'token')
            ->where('token.expiresAt < :now')
            ->andWhere('token.status IN (:statuses)')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->execute();

        $output->writeln(sprintf('Removed %d expired account token(s).', (int) $removed));

        return Command::SUCCESS;
    }
}
