<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AccountTokenCleanupCommand;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\UserAccountStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountTokenCleanupCommandTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItRemovesExpiredPendingApprovalAndRevokedTokens(): void
    {
        $issuer = new AccountTokenIssuer();
        [$expiredPending] = $issuer->issue(AccountTokenType::Invitation, 'expired-pending@example.test', ttl: '-1 hour');
        [$expiredApproval] = $issuer->issue(AccountTokenType::Registration, 'expired-approval@example.test', status: AccountTokenStatus::PendingApproval, ttl: '-1 hour');
        [$expiredRevoked] = $issuer->issue(AccountTokenType::Registration, 'expired-revoked@example.test', ttl: '-1 hour');
        [$freshPending] = $issuer->issue(AccountTokenType::Invitation, 'fresh-pending@example.test', ttl: '+1 hour');
        $expiredRevoked->revoke();

        foreach ([$expiredPending, $expiredApproval, $expiredRevoked, $freshPending] as $token) {
            $this->entityManager->persist($token);
        }

        $this->entityManager->flush();

        $tester = new CommandTester(new AccountTokenCleanupCommand($this->entityManager));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Removed 3 expired account token(s).', $tester->getDisplay());
        self::assertNull($this->findToken($expiredPending));
        self::assertNull($this->findToken($expiredApproval));
        self::assertNull($this->findToken($expiredRevoked));
        self::assertInstanceOf(AccountToken::class, $this->findToken($freshPending));
    }

    public function testItKeepsUsedSecurityReviewTokensForInactiveUsers(): void
    {
        $issuer = new AccountTokenIssuer();
        $inactiveUser = new UserAccount('61000000-0000-0000-0000-'.substr(md5('cleanupdispute'), 0, 12), 'cleanupdispute', 'cleanupdispute@example.test', 'pending', status: UserAccountStatus::Inactive);
        $activeUser = new UserAccount('61000000-0000-0000-0000-'.substr(md5('cleanupresolved'), 0, 12), 'cleanupresolved', 'cleanupresolved@example.test', 'pending');
        [$unresolvedDispute] = $issuer->issue(AccountTokenType::SecurityReview, $inactiveUser->email(), [], $inactiveUser, ttl: '-1 hour');
        [$resolvedReview] = $issuer->issue(AccountTokenType::SecurityReview, $activeUser->email(), [], $activeUser, ttl: '-1 hour');
        [$usedReset] = $issuer->issue(AccountTokenType::PasswordReset, $inactiveUser->email(), [], $inactiveUser, ttl: '-1 hour');
        $unresolvedDispute->consume($inactiveUser);
        $resolvedReview->consume($activeUser);
        $usedReset->consume($inactiveUser);

        foreach ([$inactiveUser, $activeUser, $unresolvedDispute, $resolvedReview, $usedReset] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $tester = new CommandTester(new AccountTokenCleanupCommand($this->entityManager));
        $exitCode = $tester->execute(['--include-used' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Removed 2 expired account token(s).', $tester->getDisplay());
        self::assertInstanceOf(AccountToken::class, $this->findToken($unresolvedDispute));
        self::assertNull($this->findToken($resolvedReview));
        self::assertNull($this->findToken($usedReset));
    }

    private function findToken(AccountToken $token): ?AccountToken
    {
        $this->entityManager->clear();
        $found = $this->entityManager->find(AccountToken::class, $token->uid());

        return $found instanceof AccountToken ? $found : null;
    }
}
