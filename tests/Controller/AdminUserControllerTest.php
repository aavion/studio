<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessLevel;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\ContentItem;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use App\Entity\SiteMenu;
use App\Entity\SiteMenuItem;
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

    public function testLowerAccessAdminCannotEditHigherAccessUser(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $limitedGroup = $this->createGroup('limited_admin', 8);
        $limitedAdmin = $this->createUser('limitedadmin', UserAccountStatus::Active);
        $limitedAdmin->addGroup($limitedGroup);
        $target = $this->adminUser();
        $entityManager->flush();

        $client->loginUser($limitedAdmin);
        $crawler = $client->request('GET', '/admin/users/'.$target->uid());
        $client->submit($crawler->selectButton('Save')->form([
            'status' => UserAccountStatus::Inactive->value,
        ]));

        self::assertResponseRedirects('/admin/users/'.$target->uid());

        $entityManager->clear();
        $unchangedTarget = $entityManager->find(UserAccount::class, $target->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedTarget);
        self::assertSame(UserAccountStatus::Active, $unchangedTarget->status());

        $entityManager->remove($entityManager->find(UserAccount::class, $limitedAdmin->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $limitedGroup->uid()));
        $entityManager->flush();
    }

    public function testAdminCannotRemoveOwnLastAdminAccess(): void
    {
        $client = self::createClient();
        $admin = $this->adminUser();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/users/'.$admin->uid());
        $form = $crawler->selectButton('Save')->form([
            'status' => UserAccountStatus::Active->value,
        ]);
        foreach ($form['groups'] as $groupField) {
            $groupField->untick();
        }
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/'.$admin->uid());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedAdmin = $entityManager->find(UserAccount::class, $admin->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedAdmin);
        self::assertSame(AccessLevel::ADMIN, $unchangedAdmin->maxAccessLevel());
    }

    public function testGroupDeleteRequiresReviewAndCleansAclReferences(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('review_cleanup', AccessLevel::MANAGER);
        $user = $this->createUser('groupcleanup', UserAccountStatus::Active);
        $user->addGroup($group);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'cleanup-invite@example.test',
            [$group->identifier()],
        );
        $content = new ContentItem('64000000-0000-0000-0000-000000000001', 'acl-cleanup-content');
        $content->setAclRestrictions([$group->identifier()]);
        $content->setViewRule(null, [$group->identifier()]);
        $content->setEditRule(AccessLevel::EDITOR, [$group->identifier()]);
        $content->setManageRule(AccessLevel::MANAGER, [$group->identifier()]);
        $schema = new ContentSchema('64000000-0000-0000-0000-000000000002', 'acl_cleanup_schema', ContentSchemaSource::Custom, ['en' => 'ACL cleanup']);
        $version = new ContentSchemaVersion(
            '64000000-0000-0000-0000-000000000003',
            $schema,
            1,
            ['en' => 'ACL cleanup schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                ],
            ],
            useGroupIdentifiers: [$group->identifier()],
            editGroupIdentifiers: [$group->identifier()],
            manageGroupIdentifiers: [$group->identifier()],
        );
        $menu = new SiteMenu('64000000-0000-0000-0000-000000000004', 'acl_cleanup_menu', ['en' => 'ACL cleanup']);
        $menuItem = new SiteMenuItem('64000000-0000-0000-0000-000000000005', $menu, ['en' => 'ACL cleanup'], 'route', 'content_home', viewGroupIdentifiers: [$group->identifier()]);

        $schema->addVersion($version);
        $menu->addItem($menuItem);
        $entityManager->persist($token);
        $entityManager->persist($content);
        $entityManager->persist($schema);
        $entityManager->persist($version);
        $entityManager->persist($menu);
        $entityManager->persist($menuItem);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
        $client->submit($crawler->filter('form[action="/admin/users/groups/'.$group->uid().'/delete"]')->form());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Review ACL group change');
        self::assertSelectorTextContains('main', 'groupcleanup@example.test');
        self::assertSelectorTextContains('main', 'acl-cleanup-content');
        self::assertSelectorTextContains('main', 'acl_cleanup_schema v1');
        self::assertSelectorTextContains('main', 'cleanup-invite@example.test');

        $crawler = $client->getCrawler();
        $client->submit($crawler->selectButton('Delete group and remove references')->form());

        self::assertResponseRedirects('/admin/users/groups');

        $entityManager->clear();
        $deletedGroup = $entityManager->find(AclGroup::class, $group->uid());
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());
        $updatedToken = $entityManager->find(AccountToken::class, $token->uid());
        $updatedContent = $entityManager->find(ContentItem::class, $content->uid());
        $updatedVersion = $entityManager->find(ContentSchemaVersion::class, $version->uid());
        $updatedMenuItem = $entityManager->find(SiteMenuItem::class, $menuItem->uid());

        self::assertNull($deletedGroup);
        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(0, $updatedUser->maxAccessLevel());
        self::assertInstanceOf(AccountToken::class, $updatedToken);
        self::assertSame([], $updatedToken->groupIdentifiers());
        self::assertInstanceOf(ContentItem::class, $updatedContent);
        self::assertSame([], $updatedContent->aclRestrictions());
        self::assertSame([], $updatedContent->viewGroupIdentifiers());
        self::assertSame([], $updatedContent->editGroupIdentifiers());
        self::assertSame([], $updatedContent->manageGroupIdentifiers());
        self::assertInstanceOf(ContentSchemaVersion::class, $updatedVersion);
        self::assertSame([], $updatedVersion->useGroupIdentifiers());
        self::assertSame([], $updatedVersion->editGroupIdentifiers());
        self::assertSame([], $updatedVersion->manageGroupIdentifiers());
        self::assertInstanceOf(SiteMenuItem::class, $updatedMenuItem);
        self::assertSame([], $updatedMenuItem->viewGroupIdentifiers());

        $entityManager->remove($updatedToken);
        $entityManager->remove($updatedContent);
        $entityManager->remove($entityManager->find(ContentSchema::class, $schema->uid()));
        $entityManager->remove($entityManager->find(SiteMenu::class, $menu->uid()));
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testGroupUpdateRequiresReviewConfirmation(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('review_update', AccessLevel::EDITOR);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
        $client->submit($crawler->selectButton('Save')->form([
            'name_en' => 'Review update changed',
            'name_de' => 'Review update changed',
            'access_level' => (string) AccessLevel::MANAGER,
            'allow_empty' => '1',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Review ACL group change');
        self::assertSelectorTextContains('main', '3 -> 6');

        $crawler = $client->getCrawler();
        $client->submit($crawler->selectButton('Apply group update')->form());

        self::assertResponseRedirects('/admin/users/groups/'.$group->uid());

        $entityManager->clear();
        $updatedGroup = $entityManager->find(AclGroup::class, $group->uid());

        self::assertInstanceOf(AclGroup::class, $updatedGroup);
        self::assertSame(AccessLevel::MANAGER, $updatedGroup->accessLevel());
        self::assertSame('Review update changed', $updatedGroup->name()['en']);

        $entityManager->remove($updatedGroup);
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

    private function createGroup(string $identifier, int $accessLevel): AclGroup
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingGroup = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if ($existingGroup instanceof AclGroup) {
            $existingGroup->changeAccessLevel($accessLevel);
            $entityManager->flush();

            return $existingGroup;
        }

        $group = new AclGroup(
            '62000000-0000-0000-0000-'.substr(md5($identifier), 0, 12),
            $identifier,
            ['en' => ucfirst(str_replace('_', ' ', $identifier))],
            $accessLevel,
        );
        $entityManager->persist($group);

        return $group;
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
