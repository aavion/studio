<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
{
    public function testProtectedUserRoutesRenderLoginForAnonymousUsers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/profile');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('h1', 'Sign in');
    }

    public function testUserIndexRendersLoginForAnonymousUsers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('h1', 'Sign in');
    }

    public function testUserIndexRedirectsAuthenticatedUsersToProfile(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(1, 'indexuser', 'index-password'));
        $client->request('GET', '/user');

        self::assertResponseRedirects('/user/profile');
    }

    public function testProfileRouteRendersAccountSkeleton(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(1, 'profileuser', 'profile-password'));
        $client->request('GET', '/user/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Profile');
        self::assertSelectorTextContains('.studio-user-summary', 'profileuser');
        self::assertSelectorTextContains('.studio-user-summary', 'profileuser@example.test');
    }

    public function testPasswordRouteChangesPassword(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'passworduser', 'current-password');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/user/password');
        $form = $crawler->selectButton('Update password')->form([
            'current_password' => 'current-password',
            'new_password' => 'new-password-value',
            'confirm_password' => 'new-password-value',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'Your password was updated.');
        $updatedUser = self::getContainer()->get(EntityManagerInterface::class)->getRepository(UserAccount::class)->find($user->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertFalse(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updatedUser, 'current-password'));
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updatedUser, 'new-password-value'));
    }

    public function testPasswordRouteReportsValidationErrors(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(1, 'passworderror', 'current-password'));

        $crawler = $client->request('GET', '/user/password');
        $form = $crawler->selectButton('Update password')->form([
            'current_password' => 'wrong-password',
            'new_password' => 'short',
            'confirm_password' => 'different',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-form-errors', 'The current password is not correct.');
        self::assertSelectorTextContains('.studio-form-errors', 'The new password must contain at least 12 characters.');
        self::assertSelectorTextContains('.studio-form-errors', 'The new passwords do not match.');
    }

    public function testUserSkeletonRoutesRender(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(1, 'skeletonuser', 'skeleton-password'));

        foreach ([
            '/user/api-keys' => 'API keys',
            '/user/invitations' => 'Invitations',
        ] as $path => $title) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $title);
        }
    }

    public function testApiKeysRouteListsPersistedKeysForTheCurrentUser(): void
    {
        $client = self::createClient();
        $user = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserAccount::class)
            ->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $user);

        $client->loginUser($user);
        $client->request('GET', '/user/api-keys');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API keys');
        self::assertSelectorTextContains('.studio-field-table', 'seedrw');
        self::assertSelectorTextContains('.studio-field-table', 'Read and write');
        self::assertSelectorTextContains('.studio-field-table', 'seedro');
        self::assertSelectorTextContains('.studio-field-table', 'Read only');
        self::assertSelectorTextContains('.studio-field-table', 'seedrv');
        self::assertSelectorTextContains('.studio-field-table', 'Revoked');
    }

    private function createUserWithLevel(int $level, string $username, string $password): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => $this->seededGroupIdentifier($level),
        ]);

        self::assertInstanceOf(AclGroup::class, $group);

        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            return $existingUser;
        }

        $user = new UserAccount(
            '60000000-0000-0000-0000-'.substr(md5($username), 0, 12),
            $username,
            $username.'@example.test',
            'pending',
        );
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $user->addGroup($group);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function seededGroupIdentifier(int $level): string
    {
        return match (true) {
            $level >= 8 => 'admin',
            $level >= 6 => 'manager',
            $level >= 3 => 'editor',
            default => 'registered',
        };
    }
}
