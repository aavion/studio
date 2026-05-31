<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Security\UserAccountStatus;
use App\Security\UserIdentityResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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

        $identity = $this->resolver($entityManager)->resolve($user->uid());

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

        $identity = $this->resolver()->resolve('67000000-0000-0000-0000-000000000999');

        self::assertFalse($identity->exists());
        self::assertSame('67000000-0000-0000-0000-000000000999', $identity->uid());
        self::assertSame('Deleted user', $identity->username());
        self::assertNull($identity->email());
        self::assertSame('Deleted user', $identity->displayName());
        self::assertSame(UserAccountStatus::Deleted, $identity->status());
    }

    public function testItLocalizesDeletedIdentity(): void
    {
        self::bootKernel();

        $identity = $this->resolver()->resolve('67000000-0000-0000-0000-000000000998', 'de');

        self::assertFalse($identity->exists());
        self::assertSame('Gelöschter Benutzer', $identity->username());
        self::assertSame('Gelöschter Benutzer', $identity->displayName());
    }

    private function resolver(?EntityManagerInterface $entityManager = null): UserIdentityResolver
    {
        return new UserIdentityResolver(
            $entityManager ?? self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(TranslatorInterface::class),
        );
    }
}
