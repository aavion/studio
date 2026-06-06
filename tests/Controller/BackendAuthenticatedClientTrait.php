<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\UserAccount;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

trait BackendAuthenticatedClientTrait
{
    use AuthenticatedClientTrait;

    private function createUserWithLevel(int $level): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'testuser'.$level]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus(UserAccountStatus::Active);
            $existingUser->changeRole(UserRole::fromAccessLevel($level));
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            '10000000-0000-7000-8000-00000000000'.$level,
            'testuser'.$level,
            'testuser'.$level.'@example.test',
            'hash',
            role: UserRole::fromAccessLevel($level),
        );
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function loginUserWithLevel(KernelBrowser $client, int $level): void
    {
        $this->loginTestUser($client, $this->createUserWithLevel($level));
    }

    private function followAdminRedirect(KernelBrowser $client): Crawler
    {
        return $client->followRedirect();
    }
}
