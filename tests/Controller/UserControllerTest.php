<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Core\Config\Config;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
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
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

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
        $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-audit-*.log') ?: []));
        self::assertStringContainsString('auth.password_change_success', $auditLog);
        self::assertStringContainsString('"result_status":"success"', $auditLog);
        self::assertStringNotContainsString('new-password-value', $auditLog);
    }

    public function testPasswordRouteReportsValidationErrors(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(1, 'passworderror', 'current-password'));
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

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
        $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-audit-*.log') ?: []));
        self::assertStringContainsString('auth.password_change_failed', $auditLog);
        self::assertStringContainsString('ui.user.password.errors.current_password', $auditLog);
        self::assertStringNotContainsString('wrong-password', $auditLog);
    }

    public function testApiKeysRouteRendersForAuthenticatedUsers(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(1, 'skeletonuser', 'skeleton-password'));
        $client->request('GET', '/user/api-keys');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API keys');
    }

    public function testInvitationAcceptanceRendersFromValidAccountToken(): void
    {
        $client = self::createClient();
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'invitee@example.test',
            ['registered'],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/invitation/'.$plainToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Accept invitation');
        self::assertSelectorExists('input[name="username"]');
    }

    public function testExpiredInvitationTokenCannotBeAccepted(): void
    {
        $client = self::createClient();
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'expired-invitee@example.test',
            ['registered'],
            ttl: '-1 hour',
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/invitation/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $persistedToken = $entityManager->getRepository(AccountToken::class)->find($token->uid());

        if ($persistedToken instanceof AccountToken) {
            $entityManager->remove($persistedToken);
            $entityManager->flush();
        }
    }

    public function testRegistrationForExistingAccountDoesNotCreateToken(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $config->set('user.registration.mode', 'auto_approval');
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserAccount::class)
            ->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $admin);
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $admin->email(),
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-notice', 'Your account setup link was created.');

            $token = self::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(AccountToken::class)
                ->findOneBy(['email' => $admin->email(), 'type' => AccountTokenType::Registration]);

            self::assertNull($token);
            $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
            self::assertStringContainsString('account.registration.existing_account', $messageLog);
            self::assertStringContainsString('"username":"admin"', $messageLog);
        } finally {
            $config->set('user.registration.mode', 'disabled');
        }
    }

    public function testPasswordResetRevokesPreviousPendingTokens(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'resetdedupe', 'current-password');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/user/reset-password');
        $client->submit($crawler->selectButton('Request reset link')->form([
            'email' => $user->email(),
        ]));

        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/user/reset-password');
        $client->submit($crawler->selectButton('Request reset link')->form([
            'email' => $user->email(),
        ]));

        self::assertResponseIsSuccessful();

        $tokens = $entityManager->getRepository(AccountToken::class)->findBy([
            'email' => $user->email(),
            'type' => AccountTokenType::PasswordReset,
        ]);
        $statuses = array_map(static fn (AccountToken $token): AccountTokenStatus => $token->status(), $tokens);

        self::assertCount(2, $tokens);
        self::assertCount(1, array_filter($statuses, static fn (AccountTokenStatus $status): bool => AccountTokenStatus::Pending === $status));
        self::assertCount(1, array_filter($statuses, static fn (AccountTokenStatus $status): bool => AccountTokenStatus::Revoked === $status));

        foreach ($tokens as $token) {
            $entityManager->remove($token);
        }

        $entityManager->flush();
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
        self::assertSelectorExists('#studio-show-revoked-api-keys');
        self::assertSelectorExists('.studio-api-key-row-revoked');
    }

    public function testApiKeysCanBeCreatedRevealedAndRevoked(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'apikeyflow', 'current-password');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/user/api-keys');
        $form = $crawler->selectButton('Generate key')->form([
            'prefix' => 'flowkey',
            'read_only' => '1',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-panel', 'Generated API key');
        self::assertSelectorTextContains('.studio-code', 'flowkey.');

        $apiKey = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ApiKey::class)->findOneBy([
            'prefix' => 'flowkey',
            'user' => $user,
        ]);

        self::assertInstanceOf(ApiKey::class, $apiKey);

        $crawler = $client->request('GET', '/user/api-keys/'.$apiKey->uid().'/reveal');
        $client->submit($crawler->selectButton('Reveal key')->form([
            'password' => 'current-password',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-code', 'flowkey.');

        $crawler = $client->request('GET', '/user/api-keys');
        $client->submit($crawler->selectButton('Revoke')->form());
        self::assertResponseRedirects('/user/api-keys');
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
