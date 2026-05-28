<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AccountTokenCleanupCommand;
use App\Entity\AccountToken;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
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

    private function findToken(AccountToken $token): ?AccountToken
    {
        $this->entityManager->clear();
        $found = $this->entityManager->find(AccountToken::class, $token->uid());

        return $found instanceof AccountToken ? $found : null;
    }
}
