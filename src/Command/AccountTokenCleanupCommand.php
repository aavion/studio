<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccountToken;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\UserAccountStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'account-tokens:cleanup',
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

        $tokens = $this->entityManager->createQueryBuilder()
            ->select('token', 'user')
            ->from(AccountToken::class, 'token')
            ->leftJoin('token.user', 'user')
            ->where('token.expiresAt < :now')
            ->andWhere('token.status IN (:statuses)')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getResult();
        $removed = 0;

        foreach ($tokens as $token) {
            if (!$token instanceof AccountToken) {
                continue;
            }

            if ($this->isUnresolvedSecurityReview($token)) {
                continue;
            }

            $this->entityManager->remove($token);
            ++$removed;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Removed %d expired account token(s).', (int) $removed));

        return Command::SUCCESS;
    }

    private function isUnresolvedSecurityReview(AccountToken $token): bool
    {
        return AccountTokenStatus::Used === $token->status()
            && AccountTokenType::SecurityReview === $token->type()
            && UserAccountStatus::Inactive === $token->user()?->status();
    }
}
