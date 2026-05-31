<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserAccountUniquenessGuardTest extends KernelTestCase
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

    #[DataProvider('duplicateUsers')]
    public function testItRejectsDuplicateUserAccountIdentifiersNearFlush(UserAccount $first, UserAccount $second, string $messagePart): void
    {
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage($messagePart);

        $this->entityManager->flush();
    }

    public function testItRejectsDuplicateUserAccountIdentifiersAlreadyInStorage(): void
    {
        $existing = new UserAccount(
            '68000000-0000-0000-0000-000000000010',
            'uniqueguardstored',
            'UniqueGuardStored@Example.Test',
            'pending',
        );
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $duplicate = new UserAccount(
            '68000000-0000-0000-0000-000000000011',
            'uniqueguardfresh',
            'uniqueguardstored@example.test',
            'pending',
        );
        $this->entityManager->persist($duplicate);

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage(MessageKey::USER_EMAIL_DUPLICATE);

        $this->entityManager->flush();
    }

    /**
     * @return iterable<string, array{UserAccount, UserAccount, string}>
     */
    public static function duplicateUsers(): iterable
    {
        yield 'email' => [
            new UserAccount(
                '68000000-0000-0000-0000-000000000001',
                'uniqueguardone',
                'UniqueGuard@Example.Test',
                'pending',
            ),
            new UserAccount(
                '68000000-0000-0000-0000-000000000002',
                'uniqueguardtwo',
                'uniqueguard@example.test',
                'pending',
            ),
            MessageKey::USER_EMAIL_DUPLICATE,
        ];

        yield 'username' => [
            new UserAccount(
                '68000000-0000-0000-0000-000000000003',
                'uniqueguardname',
                'uniqueguard-name-one@example.test',
                'pending',
            ),
            new UserAccount(
                '68000000-0000-0000-0000-000000000004',
                'uniqueguardname',
                'uniqueguard-name-two@example.test',
                'pending',
            ),
            MessageKey::USER_USERNAME_DUPLICATE,
        ];
    }
}
