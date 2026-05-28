<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Security\UserAccountStatus;
use App\Security\UserIdentityResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserIdentityResolverTest extends KernelTestCase
{
    public function testItReturnsExistingUserIdentity(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = new UserAccount(
            '67000000-0000-0000-0000-000000000001',
            'identityuser',
            'identityuser@example.test',
            'pending',
            ['display_name' => 'Identity User'],
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $identity = new UserIdentityResolver($entityManager)->resolve($user->uid());

        self::assertTrue($identity->exists());
        self::assertSame($user->uid(), $identity->uid());
        self::assertSame('identityuser', $identity->username());
        self::assertSame('identityuser@example.test', $identity->email());
        self::assertSame('Identity User', $identity->displayName());
        self::assertSame(UserAccountStatus::Active, $identity->status());
    }

    public function testItReturnsDeletedIdentityForMissingUser(): void
    {
        self::bootKernel();

        $identity = new UserIdentityResolver(self::getContainer()->get(EntityManagerInterface::class))
            ->resolve('67000000-0000-0000-0000-000000000999');

        self::assertFalse($identity->exists());
        self::assertSame('67000000-0000-0000-0000-000000000999', $identity->uid());
        self::assertSame('deleted user', $identity->username());
        self::assertNull($identity->email());
        self::assertSame('deleted user', $identity->displayName());
        self::assertSame(UserAccountStatus::Deleted, $identity->status());
    }
}
