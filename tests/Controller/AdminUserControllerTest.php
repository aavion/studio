<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserControllerTest extends WebTestCase
{
    public function testAdminUsersRouteRendersAccountsInvitationsAndPendingTokens(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'User management');
        self::assertSelectorExists('form[action="/admin/users/invitations"]');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/users/groups"]');
    }

    public function testAdminCanCreateInvitationToken(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Create invitation')->form([
            'email' => 'invited-admin-flow@example.test',
        ]);
        $form['groups'][0]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
            'email' => 'invited-admin-flow@example.test',
        ]);

        self::assertInstanceOf(AccountToken::class, $token);
        self::assertSame(AccountTokenStatus::Pending, $token->status());
        self::assertSame(['registered'], $token->groupIdentifiers());
        $entityManager->remove($token);
        $entityManager->flush();
    }

    public function testAdminCannotInviteExistingAccountEmail(): void
    {
        $client = self::createClient();
        $admin = $this->adminUser();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Create invitation')->form([
            'email' => $admin->email(),
        ]);
        $form['groups'][0]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $token = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(AccountToken::class)
            ->findOneBy(['email' => $admin->email(), 'type' => AccountTokenType::Invitation]);

        self::assertNull($token);
    }

    public function testAdminCanReissuePendingAccountToken(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'reissue-admin-flow@example.test',
            ['registered'],
            ttl: '-1 hour',
        );
        $originalHash = $token->tokenHash();
        $originalExpiry = $token->expiresAt();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/reissue"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $reissuedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $reissuedToken);
        self::assertSame(AccountTokenStatus::Pending, $reissuedToken->status());
        self::assertNotSame($originalHash, $reissuedToken->tokenHash());
        self::assertGreaterThan($originalExpiry, $reissuedToken->expiresAt());
        $entityManager->remove($reissuedToken);
        $entityManager->flush();
    }

    public function testAdminReviewQueueRendersContextualRowsWithoutPasswordResetTokens(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $issuer = self::getContainer()->get(AccountTokenIssuer::class);
        [$registration] = $issuer->issue(AccountTokenType::Registration, 'approval-review@example.test', ['registered'], status: AccountTokenStatus::PendingApproval);
        [$invitation] = $issuer->issue(AccountTokenType::Invitation, 'expired-invite-review@example.test', ['registered'], ttl: '-1 hour');
        [$passwordReset] = $issuer->issue(AccountTokenType::PasswordReset, $this->adminUser()->email(), [], $this->adminUser());

        foreach ([$registration, $invitation, $passwordReset] as $token) {
            $entityManager->persist($token);
        }

        $entityManager->flush();
        $client->request('GET', '/admin/users/reviews');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'User reviews');
        self::assertSelectorTextContains('.studio-review-list', 'approval-review@example.test');
        self::assertSelectorTextContains('.studio-review-list', 'Registration approval');
        self::assertSelectorTextContains('.studio-review-list', 'expired-invite-review@example.test');
        self::assertSelectorTextContains('.studio-review-list', 'Link expired');
        self::assertStringNotContainsString('Password reset', (string) $client->getResponse()->getContent());

        foreach ([$registration, $invitation, $passwordReset] as $token) {
            $entityManager->remove($token);
        }

        $entityManager->flush();
    }

    public function testAdminCanReactivateDisputedAccountFromReviewQueue(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewlocked', UserAccountStatus::Inactive);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $token->consume($user);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $client->submit($crawler->filter('form[action="/admin/users/reviews/'.$user->uid().'/reactivate"]')->form());

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());
        $updatedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(UserAccountStatus::Active, $updatedUser->status());
        self::assertInstanceOf(AccountToken::class, $updatedToken);
        $entityManager->remove($updatedToken);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testAdminCanDeleteDisputedAccountWithConfirmation(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewdelete', UserAccountStatus::Inactive);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $token->consume($user);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $form = $crawler->filter('form[action="/admin/users/reviews/'.$user->uid().'/delete"]')->form();
        $form['confirm_delete']->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());
        $updatedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(UserAccountStatus::Deleted, $updatedUser->status());
        self::assertInstanceOf(AccountToken::class, $updatedToken);
        $entityManager->remove($updatedToken);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testAdminStatusLockRevokesApiKeysAndRecoveryTokens(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('statuslock', UserAccountStatus::Active);
        $apiKey = $this->createApiKey($user, 'lockkey');
        [$resetToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
        );
        $entityManager->persist($resetToken);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/'.$user->uid());
        $client->submit($crawler->selectButton('Save')->form([
            'status' => UserAccountStatus::Inactive->value,
        ]));

        self::assertResponseRedirects('/admin/users/'.$user->uid());

        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());
        $updatedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());
        $updatedToken = $entityManager->find(AccountToken::class, $resetToken->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(UserAccountStatus::Inactive, $updatedUser->status());
        self::assertInstanceOf(ApiKey::class, $updatedApiKey);
        self::assertSame(ApiKeyStatus::Revoked, $updatedApiKey->status());
        self::assertInstanceOf(AccountToken::class, $updatedToken);
        self::assertSame(AccountTokenStatus::Revoked, $updatedToken->status());

        $entityManager->remove($updatedToken);
        $entityManager->remove($updatedApiKey);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testAdminCanCreateAclGroup(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $crawler = $client->request('GET', '/admin/users/groups');
        $form = $crawler->selectButton('Create group')->form([
            'identifier' => 'review_team',
            'name_en' => 'Review team',
            'name_de' => 'Review-Team',
            'access_level' => '6',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/groups');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => 'review_team',
        ]);

        self::assertInstanceOf(AclGroup::class, $group);
        self::assertSame(6, $group->accessLevel());
        self::assertSame(['en' => 'Review team', 'de' => 'Review-Team'], $group->name());
        $entityManager->remove($group);
        $entityManager->flush();
    }

    private function adminUser(): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $user);

        return $user;
    }

    private function createUser(string $username, UserAccountStatus $status): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus($status);
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            '61000000-0000-0000-0000-'.substr(md5($username), 0, 12),
            $username,
            $username.'@example.test',
            'pending',
            status: $status,
        );
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'current-password'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createApiKey(UserAccount $user, string $prefix): ApiKey
    {
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '62000000-0000-0000-0000-'.substr(md5($prefix.$user->uid()), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey),
            $user,
            ApiKeyStatus::ReadWrite,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);

        return $apiKey;
    }
}
