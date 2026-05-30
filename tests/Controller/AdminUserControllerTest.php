<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
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
use App\Security\AppSecretRotationGuard;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\DeletedUserCleanup;
use App\Security\UserAccountStatus;
use App\Security\UserAccountLifecycle;
use App\Security\UserRole;
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

    public function testDeletedUsersViewListsRetentionAndCleansExpiredAccounts(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalRetention = $config->get('user.deleted_user_retention_days', 7);
        $oldUser = $this->createUser('olddeleteduser', UserAccountStatus::Active);
        $recentUser = $this->createUser('recentdeleteduser', UserAccountStatus::Active);
        $activeUser = $this->createUser('stillactiveuser', UserAccountStatus::Active);
        $oldUser->addGroup($this->registeredGroup());
        $recentUser->addGroup($this->registeredGroup());
        $activeUser->addGroup($this->registeredGroup());
        $staleApiKey = $this->createApiKey($oldUser, 'oldgone');
        $entityManager->flush();
        $this->markDeletedAt($oldUser, 'cleanup-admin', '2026-05-01 10:00:00');
        $this->markDeletedAt($recentUser, 'cleanup-admin', (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));
        $config->set('user.deleted_user_retention_days', 7, ConfigValueType::Integer, modifiedBy: 'test');

        try {
            $client->request('GET', '/admin/users');

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('olddeleteduser@example.test', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('recentdeleteduser@example.test', (string) $client->getResponse()->getContent());
            self::assertSelectorExists('a[href="/admin/users/deleted"]');
            self::assertSelectorNotExists('select[name="status"] option[value="deleted"]');

            $crawler = $client->request('GET', '/admin/users/deleted');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Deleted users');
            self::assertSelectorTextContains('main', 'Retention: 7 day(s).');
            self::assertSelectorTextContains('main', 'olddeleteduser@example.test');
            self::assertSelectorTextContains('main', 'recentdeleteduser@example.test');
            self::assertSelectorTextContains('main', 'cleanup-admin');
            self::assertSelectorTextContains('main', 'Eligible for cleanup');
            self::assertSelectorTextContains('main', 'Within retention');

            $client->submit($crawler->selectButton('Clean up retained deleted users')->form());

            self::assertResponseRedirects('/admin/users/deleted');

            $entityManager->clear();
            self::assertNull($entityManager->find(UserAccount::class, $oldUser->uid()));
            self::assertInstanceOf(UserAccount::class, $entityManager->find(UserAccount::class, $recentUser->uid()));
            self::assertInstanceOf(UserAccount::class, $entityManager->find(UserAccount::class, $activeUser->uid()));
            $retainedApiKey = $entityManager->find(ApiKey::class, $staleApiKey->uid());

            self::assertInstanceOf(ApiKey::class, $retainedApiKey);
            self::assertSame(ApiKeyStatus::Revoked, $retainedApiKey->status());
            self::assertSame(DeletedUserCleanup::DELETED_USER_UID, $retainedApiKey->user()->uid());

            $client->request('GET', '/admin/users');
            self::assertStringNotContainsString('deleted-user@localhost.local', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/users/deleted');
            self::assertStringNotContainsString('deleted-user@localhost.local', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/users/'.DeletedUserCleanup::DELETED_USER_UID);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $config->set('user.deleted_user_retention_days', (int) $originalRetention, ConfigValueType::Integer, modifiedBy: 'test');

            $managedApiKey = $entityManager->find(ApiKey::class, $staleApiKey->uid());

            if ($managedApiKey instanceof ApiKey) {
                $entityManager->remove($managedApiKey);
            }

            $deletedUserAccount = $entityManager->find(UserAccount::class, DeletedUserCleanup::DELETED_USER_UID);

            if ($deletedUserAccount instanceof UserAccount) {
                $entityManager->remove($deletedUserAccount);
            }

            foreach ([$oldUser, $recentUser, $activeUser] as $user) {
                $managedUser = $entityManager->find(UserAccount::class, $user->uid());

                if ($managedUser instanceof UserAccount) {
                    $entityManager->remove($managedUser);
                }

                $entityManager->getConnection()->delete('state_marker', [
                    'subject_type' => StateSubjectType::USER_ACCOUNT,
                    'subject_uid' => $user->uid(),
                ]);
            }

            $entityManager->flush();
        }
    }

    public function testDeletedUsersCanBeActivatedAndDeactivatedFromDeletedView(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $activatedUser = $this->createUser('deletedactivate', UserAccountStatus::Active);
        $deactivatedUser = $this->createUser('deleteddeactivate', UserAccountStatus::Active);
        $activatedUser->addGroup($this->registeredGroup());
        $deactivatedUser->addGroup($this->registeredGroup());
        $entityManager->flush();
        $this->markDeletedAt($activatedUser, 'status-admin', '2026-05-10 10:00:00');
        $this->markDeletedAt($deactivatedUser, 'status-admin', '2026-05-10 10:00:00');
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $crawler = $client->request('GET', '/admin/users/deleted');
            $client->submit($crawler->filter('form[action="/admin/users/deleted/'.$activatedUser->uid().'/activate"]')->form());

            self::assertResponseRedirects('/admin/users/deleted');

            $entityManager->clear();
            $restoredUser = $entityManager->find(UserAccount::class, $activatedUser->uid());

            self::assertInstanceOf(UserAccount::class, $restoredUser);
            self::assertSame(UserAccountStatus::Active, $restoredUser->status());
            self::assertSame(['registered'], $this->userGroupIdentifiers($restoredUser));
            $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
            self::assertStringContainsString('account.restored', $messageLog);
            self::assertStringContainsString('"username":"deletedactivate"', $messageLog);

            $crawler = $client->request('GET', '/admin/users/deleted');
            $client->submit($crawler->filter('form[action="/admin/users/deleted/'.$deactivatedUser->uid().'/deactivate"]')->form());

            self::assertResponseRedirects('/admin/users/deleted');

            $entityManager->clear();
            $inactiveUser = $entityManager->find(UserAccount::class, $deactivatedUser->uid());

            self::assertInstanceOf(UserAccount::class, $inactiveUser);
            self::assertSame(UserAccountStatus::Inactive, $inactiveUser->status());
            self::assertSame(['registered'], $this->userGroupIdentifiers($inactiveUser));
        } finally {
            foreach ([$activatedUser, $deactivatedUser] as $user) {
                $managedUser = $entityManager->find(UserAccount::class, $user->uid());

                if ($managedUser instanceof UserAccount) {
                    $entityManager->remove($managedUser);
                }

                $entityManager->getConnection()->delete('state_marker', [
                    'subject_type' => StateSubjectType::USER_ACCOUNT,
                    'subject_uid' => $user->uid(),
                ]);
            }

            $entityManager->flush();
        }
    }

    public function testDeletedUserActivationRestoresPublicRole(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('deletedheal', UserAccountStatus::Active);
        $this->markDeletedAt($user, 'status-admin', '2026-05-10 10:00:00');
        $user->changeRole(UserRole::Public);
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/admin/users/deleted');
            $client->submit($crawler->filter('form[action="/admin/users/deleted/'.$user->uid().'/activate"]')->form());

            self::assertResponseRedirects('/admin/users/deleted');

            $entityManager->clear();
            $healedUser = $entityManager->find(UserAccount::class, $user->uid());

            self::assertInstanceOf(UserAccount::class, $healedUser);
            self::assertSame(UserAccountStatus::Active, $healedUser->status());
            self::assertSame([], $this->userGroupIdentifiers($healedUser));
            self::assertSame(UserRole::User, $healedUser->role());
        } finally {
            $managedUser = $entityManager->find(UserAccount::class, $user->uid());

            if ($managedUser instanceof UserAccount) {
                $entityManager->remove($managedUser);
            }

            $entityManager->getConnection()->delete('state_marker', [
                'subject_type' => StateSubjectType::USER_ACCOUNT,
                'subject_uid' => $user->uid(),
            ]);
            $entityManager->flush();
        }
    }

    public function testAdminUsersRouteSupportsSearchFiltersSortingAndPagination(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $visibleUser = $this->createUser('filtervisible', UserAccountStatus::Inactive);
        $hiddenUser = $this->createUser('filterhidden', UserAccountStatus::Active);
        $visibleUser->addGroup($this->registeredGroup());
        $hiddenUser->addGroup($this->registeredGroup());
        $entityManager->flush();

        $client->request('GET', '/admin/users?q=filtervisible&status=inactive&group=registered&sort=email&direction=desc&per_page=25');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="q"][value="filtervisible"]');
        self::assertSelectorTextContains('.studio-field-table', 'filtervisible');
        self::assertStringNotContainsString('filterhidden', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.studio-toolbar', 'Page 1 of 1');

        $entityManager->remove($entityManager->find(UserAccount::class, $visibleUser->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $hiddenUser->uid()));
        $entityManager->flush();
    }

    public function testAdminGroupsRouteSupportsSearchSortingAndPagination(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $visibleGroup = $this->createGroup('filter_group_visible', 2);
        $hiddenGroup = $this->createGroup('filter_group_hidden', 2);
        $entityManager->flush();

        $client->request('GET', '/admin/users/groups?q=filter_group_visible&sort=identifier&direction=asc&per_page=25');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="q"][value="filter_group_visible"]');
        self::assertSelectorTextContains('.studio-field-table', 'filter_group_visible');
        self::assertStringNotContainsString('filter_group_hidden', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.studio-toolbar', 'Page 1 of 1');

        $entityManager->remove($entityManager->find(AclGroup::class, $visibleGroup->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $hiddenGroup->uid()));
        $entityManager->flush();
    }

    public function testAdminCanCreateInvitationToken(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'https://example.test');
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

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
        self::assertSame(UserRole::User, $token->role());
        self::assertSame(['registered'], $token->groupIdentifiers());
        $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
        self::assertStringContainsString('https://example.test/user/invitation/', $messageLog);
        $entityManager->remove($token);
        $entityManager->flush();
        $config->set('site.url', (string) $originalSiteUrl);
    }

    public function testAdminInvitationShowsDeliveryErrorWhenSiteUrlIsInvalid(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'not-a-url');

        try {
            $crawler = $client->request('GET', '/admin/users');
            $form = $crawler->selectButton('Create invitation')->form([
                'email' => 'invalid-delivery-invite@example.test',
            ]);
            $form['groups'][0]->tick();
            $client->submit($form);

            self::assertResponseRedirects('/admin/users');
            $client->followRedirect();
            self::assertSelectorTextContains('.studio-alert-error', 'The account email could not be created. Check the configured site URL and try again.');

            $token = self::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(AccountToken::class)
                ->findOneBy(['email' => 'invalid-delivery-invite@example.test']);

            self::assertNull($token);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
        }
    }

    public function testAdminCannotInviteSelfAccountEmail(): void
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

    public function testAdminInvitationUpdatesExistingAccountWithoutDowngrade(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('existinginvitee', UserAccountStatus::Active);
        $user->changeRole(UserRole::Author);
        $user->addGroup($this->registeredGroup());
        $group = $this->createGroup('existing_invite_group', AccessLevel::USER);
        $entityManager->flush();

        $client->loginUser($this->adminUser());
        $crawler = $client->request('GET', '/admin/users');
        $client->request('POST', '/admin/users/invitations', [
            '_csrf_token' => (string) $crawler->filter('form[action="/admin/users/invitations"] input[name="_csrf_token"]')->attr('value'),
            'email' => $user->email(),
            'role' => UserRole::User->value,
            'groups' => [$group->identifier()],
        ]);

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $updatedUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'existinginvitee']);

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(UserRole::Author, $updatedUser->role());
        self::assertSame(['existing_invite_group', 'registered'], $this->userGroupIdentifiers($updatedUser));
        self::assertNull($entityManager->getRepository(AccountToken::class)->findOneBy([
            'email' => $user->email(),
            'type' => AccountTokenType::Invitation,
        ]));

        $entityManager->remove($updatedUser);
        $entityManager->remove($entityManager->find(AclGroup::class, $group->uid()));
        $entityManager->flush();
    }

    public function testAdminCanInviteDeletedAccountForReactivation(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $deletedUser = $this->createUser('deletedinvitee', UserAccountStatus::Deleted);
        $preservedGroup = $this->createGroup('deleted_reactivation_group', AccessLevel::USER);
        $deletedUser->changeRole(UserRole::Author);
        $deletedUser->addGroup($preservedGroup);
        $entityManager->flush();
        $client->loginUser($this->adminUser());
        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Create invitation')->form([
            'email' => $deletedUser->email(),
        ]);
        $form['groups'][0]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $token = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(AccountToken::class)
            ->findOneBy(['email' => $deletedUser->email(), 'type' => AccountTokenType::Invitation]);

        self::assertInstanceOf(AccountToken::class, $token);
        self::assertSame(AccountTokenStatus::Pending, $token->status());
        self::assertSame($deletedUser->uid(), $token->user()?->uid());
        self::assertSame(UserRole::Author, $token->role());
        self::assertSame(['deleted_reactivation_group'], $token->groupIdentifiers());

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $deletedUser->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $preservedGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotInviteDeletedHigherAccessAccount(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $limitedGroup = $this->createGroup('limited_deleted_inviter', 8);
        $ownerGroup = $this->createGroup('deleted_owner_invitee', 9);
        $limitedAdmin = $this->createUser('limiteddeletedinviter', UserAccountStatus::Active);
        $deletedOwner = $this->createUser('deletedownerinvitee', UserAccountStatus::Deleted);
        $limitedAdmin->changeRole(UserRole::Admin);
        $deletedOwner->changeRole(UserRole::Owner);
        $limitedAdmin->addGroup($limitedGroup);
        $deletedOwner->addGroup($ownerGroup);
        $entityManager->flush();

        $client->loginUser($limitedAdmin);
        $crawler = $client->request('GET', '/admin/users');
        $client->request('POST', '/admin/users/invitations', [
            '_csrf_token' => (string) $crawler->filter('form[action="/admin/users/invitations"] input[name="_csrf_token"]')->attr('value'),
            'email' => $deletedOwner->email(),
            'role' => UserRole::User->value,
            'groups' => ['registered'],
        ]);

        self::assertResponseRedirects('/admin/users');
        self::assertNull($entityManager->getRepository(AccountToken::class)->findOneBy([
            'email' => $deletedOwner->email(),
            'type' => AccountTokenType::Invitation,
        ]));

        $entityManager->remove($entityManager->find(UserAccount::class, $limitedAdmin->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $deletedOwner->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $limitedGroup->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $ownerGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotInvitePeerAccessAccount(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $limitedGroup = $this->createGroup('limited_inviter', 8);
        $limitedAdmin = $this->createUser('limitedinviter', UserAccountStatus::Active);
        $limitedAdmin->changeRole(UserRole::Admin);
        $limitedAdmin->addGroup($limitedGroup);
        $entityManager->flush();

        $client->loginUser($limitedAdmin);
        $crawler = $client->request('GET', '/admin/users');
        $client->request('POST', '/admin/users/invitations', [
            '_csrf_token' => (string) $crawler->filter('form[action="/admin/users/invitations"] input[name="_csrf_token"]')->attr('value'),
            'email' => 'peer-invite@example.test',
            'role' => UserRole::Admin->value,
            'groups' => ['limited_inviter'],
        ]);

        self::assertResponseRedirects('/admin/users');

        $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
            'email' => 'peer-invite@example.test',
        ]);

        self::assertNull($token);

        $entityManager->remove($entityManager->find(UserAccount::class, $limitedAdmin->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $limitedGroup->uid()));
        $entityManager->flush();
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

    public function testAdminReissueRemovesInvalidTokenGroups(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'reissue-repair@example.test',
            [],
            ttl: '-1 hour',
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/reissue"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $reissuedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $reissuedToken);
        self::assertSame([], $reissuedToken->groupIdentifiers());
        $entityManager->remove($reissuedToken);
        $entityManager->flush();
    }

    public function testAdminCanReissueRecoveryTokenWithoutGroups(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $user = $this->createUser('reissuerecovery', UserAccountStatus::Active);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
            ttl: '-1 hour',
        );
        $originalHash = $token->tokenHash();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/reissue"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $reissuedToken = $entityManager->find(AccountToken::class, $token->uid());
        $managedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(AccountToken::class, $reissuedToken);
        self::assertInstanceOf(UserAccount::class, $managedUser);
        self::assertNotSame($originalHash, $reissuedToken->tokenHash());
        self::assertSame([], $reissuedToken->groupIdentifiers());
        $entityManager->remove($reissuedToken);
        $entityManager->remove($managedUser);
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotReissueOwnerRecoveryToken(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $actorGroup = $this->createGroup('reissue_limited_admin', 8);
        $actor = $this->createUser('reissuelimited', UserAccountStatus::Active);
        $owner = $this->adminUser();
        $actor->changeRole(UserRole::Admin);
        $actor->addGroup($actorGroup);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $owner->email(),
            [],
            $owner,
        );
        $originalHash = $token->tokenHash();
        $entityManager->persist($token);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/reissue"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $unchangedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $unchangedToken);
        self::assertSame($originalHash, $unchangedToken->tokenHash());

        $entityManager->remove($unchangedToken);
        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $actorGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotApproveDeletedOwnerRegistrationToken(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $actorGroup = $this->createGroup('approve_deleted_owner_actor', 8);
        $ownerGroup = $this->createGroup('approve_deleted_owner_group', 9);
        $actor = $this->createUser('approvedeletedactor', UserAccountStatus::Active);
        $deletedOwner = $this->createUser('approvedeletedowner', UserAccountStatus::Deleted);
        $actor->changeRole(UserRole::Admin);
        $deletedOwner->changeRole(UserRole::Owner);
        $actor->addGroup($actorGroup);
        $deletedOwner->addGroup($ownerGroup);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Registration,
            $deletedOwner->email(),
            ['registered'],
            $deletedOwner,
            status: AccountTokenStatus::PendingApproval,
        );
        $entityManager->persist($token);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/approve"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $unchangedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $unchangedToken);
        self::assertSame(AccountTokenStatus::PendingApproval, $unchangedToken->status());

        $entityManager->remove($unchangedToken);
        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $deletedOwner->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $actorGroup->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $ownerGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotApproveAdminRoleRegistrationToken(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $actorGroup = $this->createGroup('approve_admin_role_actor', 8);
        $actor = $this->createUser('approveadminroleactor', UserAccountStatus::Active);
        $actor->changeRole(UserRole::Admin);
        $actor->addGroup($actorGroup);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Registration,
            'admin-role-approval@example.test',
            [],
            role: UserRole::Admin,
            status: AccountTokenStatus::PendingApproval,
        );
        $entityManager->persist($token);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/approve"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $unchangedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $unchangedToken);
        self::assertSame(AccountTokenStatus::PendingApproval, $unchangedToken->status());

        $entityManager->remove($unchangedToken);
        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $actorGroup->uid()));
        $entityManager->flush();
    }

    public function testAdminApprovalRemovesInvalidTokenGroups(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Registration,
            'approval-repair@example.test',
            [],
            status: AccountTokenStatus::PendingApproval,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/approve"]')->form());

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $approvedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $approvedToken);
        self::assertSame(AccountTokenStatus::Pending, $approvedToken->status());
        self::assertSame([], $approvedToken->groupIdentifiers());
        $entityManager->remove($approvedToken);
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

    public function testAdminReviewQueueSupportsSearchSortingAndPagination(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $issuer = self::getContainer()->get(AccountTokenIssuer::class);
        [$visible] = $issuer->issue(AccountTokenType::Registration, 'visible-review-filter@example.test', ['registered'], status: AccountTokenStatus::PendingApproval);
        [$hidden] = $issuer->issue(AccountTokenType::Invitation, 'hidden-review-filter@example.test', ['registered']);
        $entityManager->persist($visible);
        $entityManager->persist($hidden);
        $entityManager->flush();

        $client->request('GET', '/admin/users/reviews?q=visible-review-filter&sort=email&direction=asc&per_page=25');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="q"][value="visible-review-filter"]');
        self::assertSelectorTextContains('.studio-review-list', 'visible-review-filter@example.test');
        self::assertStringNotContainsString('hidden-review-filter@example.test', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.studio-toolbar', 'Page 1 of 1');

        $entityManager->remove($entityManager->find(AccountToken::class, $visible->uid()));
        $entityManager->remove($entityManager->find(AccountToken::class, $hidden->uid()));
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
        self::assertNull($updatedToken);
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
        self::assertNull($updatedToken);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testStaleDisputeDeleteFormDoesNotDeleteRecoveredAccount(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewstaledelete', UserAccountStatus::Inactive);
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
        $user->changeStatus(UserAccountStatus::Active);
        $entityManager->remove($token);
        $entityManager->flush();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame(UserAccountStatus::Active, $unchangedUser->status());

        $entityManager->remove($unchangedUser);
        $entityManager->flush();
    }

    public function testStaleDisputeReactivateFormDoesNotResetRecoveredAccount(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewstalereactivate', UserAccountStatus::Inactive);
        $originalPassword = $user->getPassword();
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
        $form = $crawler->filter('form[action="/admin/users/reviews/'.$user->uid().'/reactivate"]')->form();
        $user->changeStatus(UserAccountStatus::Active);
        $entityManager->remove($token);
        $entityManager->flush();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame(UserAccountStatus::Active, $unchangedUser->status());
        self::assertSame($originalPassword, $unchangedUser->getPassword());

        $entityManager->remove($unchangedUser);
        $entityManager->flush();
    }

    public function testAdminStatusLockRevokesApiKeysAndRecoveryTokens(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('statuslock', UserAccountStatus::Active);
        $user->addGroup($this->registeredGroup());
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

        $client->request('GET', '/admin/users/'.$updatedUser->uid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Account history');
        self::assertSelectorTextContains('.studio-field-table', 'Status changed');
        self::assertSelectorExists('a[href*="/admin/logs"][href*="source=audit"][href*="'.$updatedUser->uid().'"]');

        $entityManager->remove($updatedToken);
        $entityManager->remove($updatedApiKey);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testAdminCannotIssuePasswordResetForInactiveUser(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('inactiveadminreset', UserAccountStatus::Inactive);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/'.$user->uid());
        $client->request('POST', '/admin/users/'.$user->uid().'/password-reset', [
            '_csrf_token' => (string) $crawler->filter('form[action="/admin/users/'.$user->uid().'/password-reset"] input[name="_csrf_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/admin/users/'.$user->uid());
        self::assertNull($entityManager->getRepository(AccountToken::class)->findOneBy([
            'user' => $user,
            'type' => AccountTokenType::PasswordReset,
        ]));

        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testAppSecretRotationRevokesApiKeysAndIssuesOwnerPasswordReset(): void
    {
        $client = self::createClient();
        $admin = $this->adminUser();
        $client->loginUser($admin);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $config = self::getContainer()->get(Config::class);
        $originalFingerprints = $config->get(AppSecretRotationGuard::FINGERPRINTS_KEY, []);
        $activeKeyRows = $connection->fetchAllAssociative("SELECT uid, status FROM api_key WHERE status IN ('read_only', 'read_write')");
        $existingResetTokenUids = $connection->fetchFirstColumn(
            "SELECT uid FROM account_token WHERE user_uid = ? AND type = 'password_reset'",
            [$admin->uid()],
        );
        $apiKey = $this->createApiKey($admin, 'rotkey');
        $entityManager->flush();

        try {
            $config->set(AppSecretRotationGuard::FINGERPRINTS_KEY, ['test' => 'previous-secret-fingerprint'], ConfigValueType::Json, sensitive: true);

            $client->request('GET', '/admin/users');

            self::assertResponseIsSuccessful();
            $updatedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());
            self::assertInstanceOf(ApiKey::class, $updatedApiKey);
            self::assertSame(ApiKeyStatus::Revoked, $updatedApiKey->status());
            $fingerprints = $config->get(AppSecretRotationGuard::FINGERPRINTS_KEY, []);
            self::assertIsArray($fingerprints);
            self::assertArrayHasKey('test', $fingerprints);
            self::assertNotSame('previous-secret-fingerprint', $fingerprints['test']);
            $newResetTokenUids = array_values(array_diff(
                array_map('strval', $connection->fetchFirstColumn("SELECT uid FROM account_token WHERE user_uid = ? AND type = 'password_reset'", [$admin->uid()])),
                array_map('strval', $existingResetTokenUids),
            ));
            self::assertNotEmpty($newResetTokenUids);
        } finally {
            $config->set(
                AppSecretRotationGuard::FINGERPRINTS_KEY,
                is_array($originalFingerprints) ? $originalFingerprints : [],
                ConfigValueType::Json,
                sensitive: true,
            );

            $currentResetTokenUids = array_values(array_diff(
                array_map('strval', $connection->fetchFirstColumn("SELECT uid FROM account_token WHERE user_uid = ? AND type = 'password_reset'", [$admin->uid()])),
                array_map('strval', $existingResetTokenUids),
            ));

            foreach ($currentResetTokenUids as $tokenUid) {
                $token = $entityManager->find(AccountToken::class, $tokenUid);

                if ($token instanceof AccountToken) {
                    $entityManager->remove($token);
                }
            }

            $storedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());

            if ($storedApiKey instanceof ApiKey) {
                $entityManager->remove($storedApiKey);
            }

            $entityManager->flush();

            foreach ($activeKeyRows as $row) {
                $connection->update('api_key', ['status' => (string) $row['status'], 'revoked_at' => null], ['uid' => (string) $row['uid']]);
            }

            $entityManager->clear();
        }
    }

    public function testAppSecretRotationRetriesWhenOwnerResetLinksCannotBeGenerated(): void
    {
        $client = self::createClient();
        $admin = $this->adminUser();
        $client->loginUser($admin);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $originalFingerprints = $config->get(AppSecretRotationGuard::FINGERPRINTS_KEY, []);
        $activeKeyRows = $connection->fetchAllAssociative("SELECT uid, status FROM api_key WHERE status IN ('read_only', 'read_write')");
        $apiKey = $this->createApiKey($admin, 'retryrotkey');
        $entityManager->flush();
        $config->set('site.url', 'not-a-url');
        $config->set(AppSecretRotationGuard::FINGERPRINTS_KEY, ['test' => 'previous-secret-fingerprint'], ConfigValueType::Json, sensitive: true);

        try {
            $client->request('GET', '/admin/users');

            self::assertResponseIsSuccessful();
            $fingerprints = $config->get(AppSecretRotationGuard::FINGERPRINTS_KEY, []);
            self::assertIsArray($fingerprints);
            self::assertSame('previous-secret-fingerprint', $fingerprints['test'] ?? null);
            $updatedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());
            self::assertInstanceOf(ApiKey::class, $updatedApiKey);
            self::assertSame(ApiKeyStatus::Revoked, $updatedApiKey->status());
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
            $config->set(
                AppSecretRotationGuard::FINGERPRINTS_KEY,
                is_array($originalFingerprints) ? $originalFingerprints : [],
                ConfigValueType::Json,
                sensitive: true,
            );

            $storedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());

            if ($storedApiKey instanceof ApiKey) {
                $entityManager->remove($storedApiKey);
                $entityManager->flush();
            }

            foreach ($activeKeyRows as $row) {
                $connection->update('api_key', ['status' => (string) $row['status'], 'revoked_at' => null], ['uid' => (string) $row['uid']]);
            }

            $entityManager->clear();
        }
    }

    public function testLowerAccessAdminCannotEditHigherAccessUser(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $limitedGroup = $this->createGroup('limited_admin', 8);
        $limitedAdmin = $this->createUser('limitedadmin', UserAccountStatus::Active);
        $limitedAdmin->changeRole(UserRole::Admin);
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

    public function testLowerAccessAdminCannotEditPeerAccessUser(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $peerGroup = $this->createGroup('peer_admin', 8);
        $actor = $this->createUser('peeractor', UserAccountStatus::Active);
        $target = $this->createUser('peertarget', UserAccountStatus::Active);
        $actor->changeRole(UserRole::Admin);
        $target->changeRole(UserRole::Admin);
        $actor->addGroup($peerGroup);
        $target->addGroup($peerGroup);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users/'.$target->uid());
        $client->submit($crawler->selectButton('Save')->form([
            'status' => UserAccountStatus::Inactive->value,
        ]));

        self::assertResponseRedirects('/admin/users/'.$target->uid());

        $entityManager->clear();
        $unchangedTarget = $entityManager->find(UserAccount::class, $target->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedTarget);
        self::assertSame(UserAccountStatus::Active, $unchangedTarget->status());

        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $target->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $peerGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotAssignPeerAccessGroupToUser(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $peerGroup = $this->createGroup('peer_assignment_admin', 8);
        $actor = $this->createUser('peerassigner', UserAccountStatus::Active);
        $target = $this->createUser('peerassigned', UserAccountStatus::Active);
        $actor->changeRole(UserRole::Admin);
        $actor->addGroup($peerGroup);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users/'.$target->uid());
        $client->request('POST', '/admin/users/'.$target->uid(), [
            '_csrf_token' => (string) $crawler->filter('form.studio-backend-form input[name="_csrf_token"]')->attr('value'),
            'status' => UserAccountStatus::Active->value,
            'role' => UserRole::User->value,
            'groups' => ['peer_assignment_admin'],
        ]);

        self::assertResponseRedirects('/admin/users/'.$target->uid());

        $entityManager->clear();
        $unchangedTarget = $entityManager->find(UserAccount::class, $target->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedTarget);
        self::assertSame(AccessLevel::USER, $unchangedTarget->accessLevel());
        self::assertSame([], $this->userGroupIdentifiers($unchangedTarget));

        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $target->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $peerGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotReissuePeerAccessAccountLink(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $peerGroup = $this->createGroup('peer_link_admin', 8);
        $actor = $this->createUser('peerlinkactor', UserAccountStatus::Active);
        $actor->changeRole(UserRole::Admin);
        $actor->addGroup($peerGroup);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'peer-link@example.test',
            ['peer_link_admin'],
            ttl: '-1 hour',
        );
        $originalHash = $token->tokenHash();
        $entityManager->persist($token);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/reissue"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $unchangedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $unchangedToken);
        self::assertSame($originalHash, $unchangedToken->tokenHash());

        $entityManager->remove($unchangedToken);
        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $peerGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotRevokeOwnerRecoveryToken(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $actorGroup = $this->createGroup('revoke_limited_admin', 8);
        $actor = $this->createUser('revokelimited', UserAccountStatus::Active);
        $owner = $this->adminUser();
        $actor->changeRole(UserRole::Admin);
        $actor->addGroup($actorGroup);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $owner->email(),
            [],
            $owner,
        );
        $entityManager->persist($token);
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/revoke"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $unchangedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $unchangedToken);
        self::assertSame(AccountTokenStatus::Pending, $unchangedToken->status());

        $entityManager->remove($unchangedToken);
        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $actorGroup->uid()));
        $entityManager->flush();
    }

    public function testAdminCanRevokeInvitationWithStaleEmptyGroups(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'stale-empty-revoke@example.test',
            [],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter('form[action="/admin/users/invitations/'.$token->uid().'/revoke"]')->form());

        self::assertResponseRedirects('/admin/users');

        $entityManager->clear();
        $revokedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(AccountToken::class, $revokedToken);
        self::assertSame(AccountTokenStatus::Revoked, $revokedToken->status());

        $entityManager->remove($revokedToken);
        $entityManager->flush();
    }

    public function testLowerAccessAdminOnlySeesAssignableGroupsInUserForms(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $peerGroup = $this->createGroup('peer_visible_admin', 8);
        $lowerGroup = $this->createGroup('lower_visible_manager', AccessLevel::MANAGER);
        $actor = $this->createUser('visibleassigner', UserAccountStatus::Active);
        $target = $this->createUser('visibleassigned', UserAccountStatus::Active);
        $actor->changeRole(UserRole::Admin);
        $actor->addGroup($peerGroup);
        $target->addGroup($this->registeredGroup());
        $entityManager->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users');
        $inviteForm = $crawler->filter('form[action="/admin/users/invitations"]');

        self::assertSame(0, $inviteForm->filter('input[value="lower_visible_manager"]')->count());
        self::assertSame(0, $inviteForm->filter('input[value="peer_visible_admin"]')->count());

        $crawler = $client->request('GET', '/admin/users/'.$target->uid());
        $detailForm = $crawler->filter('form.studio-backend-form')->first();

        self::assertSame(0, $detailForm->filter('input[value="lower_visible_manager"]')->count());
        self::assertSame(0, $detailForm->filter('input[value="peer_visible_admin"]')->count());

        $entityManager->remove($entityManager->find(UserAccount::class, $actor->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $target->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $peerGroup->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $lowerGroup->uid()));
        $entityManager->flush();
    }

    public function testAdminCanRemoveAllGroupsFromRegisteredUser(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('nogroupremove', UserAccountStatus::Active);
        $user->addGroup($this->registeredGroup());
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/'.$user->uid());
        $form = $crawler->selectButton('Save')->form([
            'status' => UserAccountStatus::Active->value,
        ]);
        foreach ($form['groups'] as $groupField) {
            $groupField->untick();
        }
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/'.$user->uid());

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame([], $this->userGroupIdentifiers($unchangedUser));
        self::assertSame(AccessLevel::USER, $unchangedUser->accessLevel());

        $entityManager->remove($unchangedUser);
        $entityManager->flush();
    }

    public function testAdminCanAssignPublicGroupToRegisteredUser(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $publicGroup = $this->createGroup('public_only', AccessLevel::PUBLIC);
        $user = $this->createUser('publiconlyuser', UserAccountStatus::Active);
        $user->addGroup($this->registeredGroup());
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/'.$user->uid());
        $client->request('POST', '/admin/users/'.$user->uid(), [
            '_csrf_token' => (string) $crawler->filter('form.studio-backend-form input[name="_csrf_token"]')->attr('value'),
            'status' => UserAccountStatus::Active->value,
            'role' => UserRole::User->value,
            'groups' => ['public_only'],
        ]);

        self::assertResponseRedirects('/admin/users/'.$user->uid());

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame(['public_only'], $this->userGroupIdentifiers($unchangedUser));
        self::assertSame(AccessLevel::USER, $unchangedUser->accessLevel());

        $entityManager->remove($unchangedUser);
        $entityManager->remove($entityManager->find(AclGroup::class, $publicGroup->uid()));
        $entityManager->flush();
    }

    public function testLowerAccessAdminCannotCreatePeerAccessGroup(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $limitedGroup = $this->createGroup('limited_admin_create', 8);
        $limitedAdmin = $this->createUser('limitedcreate', UserAccountStatus::Active);
        $limitedAdmin->changeRole(UserRole::Admin);
        $limitedAdmin->addGroup($limitedGroup);
        $entityManager->flush();

        $client->loginUser($limitedAdmin);
        $crawler = $client->request('GET', '/admin/users/groups');
        $client->submit($crawler->selectButton('Create group')->form([
            'identifier' => 'peer_created_group',
            'name_en' => 'Peer created group',
            'name_de' => 'Peer created group',
            'min_role' => '8',
        ]));

        self::assertResponseRedirects('/admin/users/groups');

        $createdGroup = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => 'peer_created_group',
        ]);

        self::assertNull($createdGroup);

        $entityManager->remove($entityManager->find(UserAccount::class, $limitedAdmin->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $limitedGroup->uid()));
        $entityManager->flush();
    }

    public function testAdminCannotRemoveOwnLastOwnerRole(): void
    {
        $client = self::createClient();
        $admin = $this->adminUser();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/users/'.$admin->uid());
        $form = $crawler->selectButton('Save')->form([
            'status' => UserAccountStatus::Active->value,
            'role' => UserRole::User->value,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/'.$admin->uid());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedAdmin = $entityManager->find(UserAccount::class, $admin->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedAdmin);
        self::assertSame(AccessLevel::OWNER, $unchangedAdmin->accessLevel());
        self::assertSame(['admin'], $this->userGroupIdentifiers($unchangedAdmin));
    }

    public function testGroupDeleteRequiresReviewAndCleansAclReferences(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('review_cleanup', AccessLevel::MANAGER);
        $user = $this->createUser('groupcleanup', UserAccountStatus::Active);
        $user->addGroup($this->registeredGroup());
        $user->addGroup($group);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'cleanup-invite@example.test',
            [$group->identifier()],
        );
        $content = new ContentItem('64000000-0000-0000-0000-000000000001', 'acl-cleanup-content');
        $content->setAclRestrictions([$group->identifier()]);
        $content->setViewRule(AccessLevel::PUBLIC);
        $content->setEditRule(AccessLevel::AUTHOR, [$group->identifier()]);
        $content->setManageRule(AccessLevel::MANAGER, [$group->identifier()]);
        $content->publish();
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
        self::assertSelectorTextContains('main', 'Published content may become public');
        self::assertSelectorTextContains('main', 'acl_cleanup_schema v1');
        self::assertSelectorTextContains('main', 'cleanup-invite@example.test');
        self::assertSelectorExists('form[data-controller="operation-overlay"]');

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
        self::assertSame(AccessLevel::USER, $updatedUser->accessLevel());
        self::assertSame(['registered'], $this->userGroupIdentifiers($updatedUser));
        self::assertInstanceOf(AccountToken::class, $updatedToken);
        self::assertSame([], $updatedToken->groupIdentifiers());
        self::assertInstanceOf(ContentItem::class, $updatedContent);
        self::assertSame([], $updatedContent->aclRestrictions());
        self::assertNull($updatedContent->viewGroupIdentifiers());
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

    public function testGroupDeleteAllowsUsersToLoseLastGroup(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('only_group_delete', AccessLevel::USER);
        $user = $this->createUser('onlygroupdelete', UserAccountStatus::Active);
        $user->addGroup($group);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
        $client->submit($crawler->filter('form[action="/admin/users/groups/'.$group->uid().'/delete"]')->form());

        self::assertResponseIsSuccessful();
        $client->submit($client->getCrawler()->selectButton('Delete group and remove references')->form());
        self::assertResponseRedirects('/admin/users/groups');

        $entityManager->clear();
        $unchangedGroup = $entityManager->find(AclGroup::class, $group->uid());
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertNull($unchangedGroup);
        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame([], $this->userGroupIdentifiers($unchangedUser));

        $entityManager->remove($unchangedUser);
        $entityManager->flush();
    }

    public function testGroupUpdateAllowsPublicMinimumRole(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('floor_update_group', AccessLevel::USER);
        $user = $this->createUser('floorupdateuser', UserAccountStatus::Active);
        $user->addGroup($group);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
        $client->submit($crawler->selectButton('Save')->form([
            'name_en' => 'Floor update group',
            'name_de' => 'Floor update group',
            'min_role' => (string) AccessLevel::PUBLIC,
        ]));

        self::assertResponseIsSuccessful();
        $client->submit($client->getCrawler()->selectButton('Apply group update')->form());
        self::assertResponseRedirects('/admin/users/groups/'.$group->uid());

        $entityManager->clear();
        $unchangedGroup = $entityManager->find(AclGroup::class, $group->uid());
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(AclGroup::class, $unchangedGroup);
        self::assertSame(AccessLevel::PUBLIC, $unchangedGroup->minRole());
        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame(AccessLevel::USER, $unchangedUser->accessLevel());

        $entityManager->remove($unchangedUser);
        $entityManager->remove($unchangedGroup);
        $entityManager->flush();
    }

    public function testDefaultRegistrationGroupCannotBeDeleted(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalDefaultGroup = $config->get('user.default_acl_group', 'registered');
        $group = $this->createGroup('default_delete_guard', AccessLevel::USER);
        $entityManager->flush();
        $config->set('user.default_acl_group', 'default_delete_guard', ConfigValueType::String, modifiedBy: 'test');

        try {
            $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
            $client->submit($crawler->filter('form[action="/admin/users/groups/'.$group->uid().'/delete"]')->form());

            self::assertResponseRedirects('/admin/users/groups/'.$group->uid());

            $entityManager->clear();
            $unchangedGroup = $entityManager->find(AclGroup::class, $group->uid());

            self::assertInstanceOf(AclGroup::class, $unchangedGroup);
            self::assertSame(AccessLevel::USER, $unchangedGroup->minRole());
        } finally {
            $config->set('user.default_acl_group', (string) $originalDefaultGroup, ConfigValueType::String, modifiedBy: 'test');
            $managedGroup = $entityManager->find(AclGroup::class, $group->uid());

            if ($managedGroup instanceof AclGroup) {
                $entityManager->remove($managedGroup);
                $entityManager->flush();
            }
        }
    }

    public function testDefaultRegistrationGroupCanUsePublicMinimumRole(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalDefaultGroup = $config->get('user.default_acl_group', 'registered');
        $group = $this->createGroup('default_level_guard', AccessLevel::USER);
        $entityManager->flush();
        $config->set('user.default_acl_group', 'default_level_guard', ConfigValueType::String, modifiedBy: 'test');

        try {
            $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
            $client->submit($crawler->selectButton('Save')->form([
                'name_en' => 'Default level guard',
                'name_de' => 'Default level guard',
                'min_role' => (string) AccessLevel::PUBLIC,
            ]));

            self::assertResponseIsSuccessful();
            $client->submit($client->getCrawler()->selectButton('Apply group update')->form());
            self::assertResponseRedirects('/admin/users/groups/'.$group->uid());

            $entityManager->clear();
            $unchangedGroup = $entityManager->find(AclGroup::class, $group->uid());

            self::assertInstanceOf(AclGroup::class, $unchangedGroup);
            self::assertSame(AccessLevel::PUBLIC, $unchangedGroup->minRole());
        } finally {
            $config->set('user.default_acl_group', (string) $originalDefaultGroup, ConfigValueType::String, modifiedBy: 'test');
            $managedGroup = $entityManager->find(AclGroup::class, $group->uid());

            if ($managedGroup instanceof AclGroup) {
                $entityManager->remove($managedGroup);
                $entityManager->flush();
            }
        }
    }

    public function testGroupUpdateRequiresReviewConfirmation(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('review_update', AccessLevel::AUTHOR);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/groups/'.$group->uid());
        $client->submit($crawler->selectButton('Save')->form([
            'name_en' => 'Review update changed',
            'name_de' => 'Review update changed',
            'min_role' => (string) AccessLevel::MANAGER,
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Review ACL group change');
        self::assertSelectorTextContains('main', '3 -> 6');
        self::assertSelectorExists('form[data-controller="operation-overlay"]');

        $crawler = $client->getCrawler();
        $client->submit($crawler->selectButton('Apply group update')->form());

        self::assertResponseRedirects('/admin/users/groups/'.$group->uid());

        $entityManager->clear();
        $updatedGroup = $entityManager->find(AclGroup::class, $group->uid());

        self::assertInstanceOf(AclGroup::class, $updatedGroup);
        self::assertSame(AccessLevel::MANAGER, $updatedGroup->minRole());
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
            'min_role' => '6',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/groups');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => 'review_team',
        ]);

        self::assertInstanceOf(AclGroup::class, $group);
        self::assertSame(6, $group->minRole());
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

    private function markDeletedAt(UserAccount $user, string $deletedBy, string $deletedAt): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(UserAccountLifecycle::class)->changeStatus($user, UserAccountStatus::Deleted, $deletedBy);
        $entityManager->flush();
        $entityManager->getConnection()->update('state_marker', [
            'marker_at' => $deletedAt,
        ], [
            'subject_type' => StateSubjectType::USER_ACCOUNT,
            'subject_uid' => $user->uid(),
            'marker_key' => StateMarkerKey::STATUS_CHANGED,
        ]);
    }

    private function createGroup(string $identifier, int $accessLevel): AclGroup
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingGroup = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if ($existingGroup instanceof AclGroup) {
            $existingGroup->changeMinRole($accessLevel);
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

    private function registeredGroup(): AclGroup
    {
        $group = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(AclGroup::class)
            ->findOneBy(['identifier' => 'registered']);

        self::assertInstanceOf(AclGroup::class, $group);

        return $group;
    }

    /**
     * @return list<string>
     */
    private function userGroupIdentifiers(UserAccount $user): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup) {
                $identifiers[] = $group->identifier();
            }
        }

        sort($identifiers);

        return $identifiers;
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
