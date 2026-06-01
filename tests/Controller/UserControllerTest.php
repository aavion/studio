<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Core\Config\Config;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
{
    public function testProtectedUserRoutesRenderLoginForAnonymousUsers(): void
    {
        $client = self::createClient();

        foreach (['/user/profile', '/user'] as $path) {
            $client->request('GET', $path);

            self::assertResponseStatusCodeSame(401);
            self::assertSelectorTextContains('h1', 'Sign in');
        }
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
        self::assertSelectorNotExists('input[name="username"]');
    }

    public function testProfileUsernameChangeRequiresSetting(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $user = $this->createUserWithLevel(1, 'stableprofile', 'profile-password');
        $config->set('user.username_change.enabled', false);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/user/profile');
        $client->request('POST', '/user/profile', [
            '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'username' => 'ChangedProfile',
            'email' => $user->email(),
            'display_name' => 'Stable Profile',
            'language' => 'default',
        ]);

        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame('stableprofile', $unchangedUser->username());
    }

    public function testProfileUsernameCanBeChangedWhenSettingIsEnabled(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $user = $this->createUserWithLevel(1, 'renameprofile', 'profile-password');
        $config->set('user.username_change.enabled', true);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/user/profile');

            self::assertSelectorExists('input[name="username"]');

            $client->submit($crawler->selectButton('Save profile')->form([
                'username' => 'Renamed_Profile',
                'email' => $user->email(),
                'display_name' => 'Renamed Profile',
                'language' => 'default',
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-notice', 'Profile saved.');

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();
            $renamedUser = $entityManager->find(UserAccount::class, $user->uid());

            self::assertInstanceOf(UserAccount::class, $renamedUser);
            self::assertSame('Renamed_Profile', $renamedUser->username());
        } finally {
            $config->set('user.username_change.enabled', false);
        }
    }

    public function testProfileUsernameChangeRejectsDuplicateUsername(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $user = $this->createUserWithLevel(1, 'duplicateprofile', 'profile-password');
        $this->createUserWithLevel(1, 'takenprofile', 'profile-password');
        $config->set('user.username_change.enabled', true);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/user/profile');
            $client->submit($crawler->selectButton('Save profile')->form([
                'username' => 'takenprofile',
                'email' => $user->email(),
                'display_name' => 'Duplicate Profile',
                'language' => 'default',
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-form-errors', 'This username is already used.');

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();
            $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

            self::assertInstanceOf(UserAccount::class, $unchangedUser);
            self::assertSame('duplicateprofile', $unchangedUser->username());
        } finally {
            $config->set('user.username_change.enabled', false);
        }
    }

    public function testProfileEmailCanBeChanged(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'emailprofile', 'profile-password');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => 'changed-emailprofile@example.test',
            'display_name' => 'Email Profile',
            'language' => 'default',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'Profile saved.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame('changed-emailprofile@example.test', $updatedUser->email());
    }

    public function testProfileEmailChangeRevokesPendingRecoveryTokens(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'emailrecovery', 'profile-password');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$resetToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(AccountTokenType::PasswordReset, $user->email(), [], $user);
        [$reviewToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(AccountTokenType::SecurityReview, $user->email(), [], $user);
        $entityManager->persist($resetToken);
        $entityManager->persist($reviewToken);
        $entityManager->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => 'changed-emailrecovery@example.test',
            'display_name' => 'Email Recovery',
            'language' => 'default',
        ]));

        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $updatedReset = $entityManager->find(AccountToken::class, $resetToken->uid());
        $updatedReview = $entityManager->find(AccountToken::class, $reviewToken->uid());

        self::assertInstanceOf(AccountToken::class, $updatedReset);
        self::assertInstanceOf(AccountToken::class, $updatedReview);
        self::assertSame(AccountTokenStatus::Revoked, $updatedReset->status());
        self::assertSame(AccountTokenStatus::Revoked, $updatedReview->status());
    }

    public function testProfileEmailChangeRejectsDuplicateEmail(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'emailduplicate', 'profile-password');
        $taken = $this->createUserWithLevel(1, 'emailtaken', 'profile-password');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => $taken->email(),
            'display_name' => 'Email Duplicate',
            'language' => 'default',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-form-errors', 'This email address is already used.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame('emailduplicate@example.test', $unchangedUser->email());
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

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        $crawler = $client->request('GET', '/user/password');
        $form = $crawler->selectButton('Update password')->form([
            'current_password' => 'current-password',
            'new_password' => 'NewPassword1!',
            'confirm_password' => 'NewPassword1!',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'Your password was updated.');
        $updatedUser = self::getContainer()->get(EntityManagerInterface::class)->getRepository(UserAccount::class)->find($user->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertFalse(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updatedUser, 'current-password'));
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updatedUser, 'NewPassword1!'));
        $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-audit-*.log') ?: []));
        self::assertStringContainsString('auth.password_change_success', $auditLog);
        self::assertStringContainsString('"result_status":"success"', $auditLog);
        self::assertStringNotContainsString('NewPassword1!', $auditLog);
        $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
        self::assertStringContainsString('account.password.changed', $messageLog);
        self::assertStringContainsString('/user/security-review/', $messageLog);

        $reviewToken = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AccountToken::class)->findOneBy([
            'user' => $updatedUser,
            'type' => AccountTokenType::SecurityReview,
            'status' => AccountTokenStatus::Pending,
        ]);

        self::assertInstanceOf(AccountToken::class, $reviewToken);
        $passwordMarker = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            "SELECT marker_by, marker_value FROM state_marker WHERE subject_type = 'user_account' AND subject_uid = ? AND marker_key = 'password_changed'",
            [$updatedUser->uid()],
        );

        self::assertIsArray($passwordMarker);
        self::assertSame($updatedUser->username(), $passwordMarker['marker_by']);
        self::assertSame('profile', $passwordMarker['marker_value']);
    }

    public function testPasswordRouteFailsWhenSecurityReviewLinkCannotBeBuilt(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'passworddelivery', 'current-password');
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'not-a-url');
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/user/password');
            $client->submit($crawler->selectButton('Update password')->form([
                'current_password' => 'current-password',
                'new_password' => 'NewPassword1!',
                'confirm_password' => 'NewPassword1!',
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-form-errors', 'The password-change security email could not be created. Please try again later.');

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $updatedUser = $entityManager->getRepository(UserAccount::class)->find($user->uid());
            $reviewToken = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'user' => $updatedUser,
                'type' => AccountTokenType::SecurityReview,
            ]);

            self::assertInstanceOf(UserAccount::class, $updatedUser);
            self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updatedUser, 'current-password'));
            self::assertNull($reviewToken);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
        }
    }

    public function testSecurityReviewLinkLocksAccountAndNotifiesAdmin(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'securityreview', 'current-password');
        $apiKey = $this->createApiKey($user, 'lockrev');
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        [$resetToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->persist($resetToken);
        $entityManager->flush();
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        $crawler = $client->request('GET', '/user/security-review/'.$plainToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Account security review');
        self::assertSelectorTextContains('.studio-auth-notice', 'Only continue if you did not request the password change.');
        self::assertSelectorExists('form button');

        $entityManager->clear();
        $activeUser = $entityManager->find(UserAccount::class, $user->uid());
        $pendingToken = $entityManager->find(AccountToken::class, $token->uid());
        self::assertInstanceOf(UserAccount::class, $activeUser);
        self::assertSame(UserAccountStatus::Active, $activeUser->status());
        self::assertInstanceOf(AccountToken::class, $pendingToken);
        self::assertSame(AccountTokenStatus::Pending, $pendingToken->status());

        $form = $crawler->selectButton('Lock account')->form();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'An administrator notification was created for review.');

        $entityManager->clear();
        $lockedUser = $entityManager->find(UserAccount::class, $user->uid());
        $usedToken = $entityManager->find(AccountToken::class, $token->uid());
        $revokedResetToken = $entityManager->find(AccountToken::class, $resetToken->uid());
        $revokedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());

        self::assertInstanceOf(UserAccount::class, $lockedUser);
        self::assertSame(UserAccountStatus::Inactive, $lockedUser->status());
        self::assertInstanceOf(AccountToken::class, $usedToken);
        self::assertSame(AccountTokenStatus::Used, $usedToken->status());
        self::assertInstanceOf(AccountToken::class, $revokedResetToken);
        self::assertSame(AccountTokenStatus::Revoked, $revokedResetToken->status());
        self::assertInstanceOf(ApiKey::class, $revokedApiKey);
        self::assertSame(ApiKeyStatus::Revoked, $revokedApiKey->status());
        $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
        self::assertStringContainsString('account.password_change.disputed', $messageLog);
        self::assertStringContainsString('"username":"securityreview"', $messageLog);
    }

    public function testSecurityReviewLinkCannotLockLastActiveOwner(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $entityManager
            ->getRepository(UserAccount::class)
            ->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $admin);

        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $admin->email(),
            [],
            $admin,
        );
        $restoredStatuses = [];

        foreach ($entityManager->getRepository(UserAccount::class)->findBy(['status' => UserAccountStatus::Active]) as $user) {
            if (!$user instanceof UserAccount || $user->uid() === $admin->uid() || AccessLevel::OWNER > $user->accessLevel()) {
                continue;
            }

            $restoredStatuses[$user->uid()] = $user->status();
            $user->changeStatus(UserAccountStatus::Inactive);
        }

        try {
            $entityManager->persist($token);
            $entityManager->flush();

            $crawler = $client->request('GET', '/user/security-review/'.$plainToken);
            $client->submit($crawler->selectButton('Lock account')->form());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-error', 'The last active owner account cannot be locked from this review link.');

            $entityManager->clear();
            $unchangedAdmin = $entityManager->find(UserAccount::class, $admin->uid());
            $unchangedToken = $entityManager->find(AccountToken::class, $token->uid());

            self::assertInstanceOf(UserAccount::class, $unchangedAdmin);
            self::assertSame(UserAccountStatus::Active, $unchangedAdmin->status());
            self::assertInstanceOf(AccountToken::class, $unchangedToken);
            self::assertSame(AccountTokenStatus::Pending, $unchangedToken->status());
        } finally {
            $managedToken = $entityManager->find(AccountToken::class, $token->uid());

            if ($managedToken instanceof AccountToken) {
                $entityManager->remove($managedToken);
            }

            foreach ($restoredStatuses as $uid => $status) {
                $managedUser = $entityManager->find(UserAccount::class, $uid);

                if ($managedUser instanceof UserAccount) {
                    $managedUser->changeStatus($status);
                }
            }

            $managedAdmin = $entityManager->find(UserAccount::class, $admin->uid());

            if ($managedAdmin instanceof UserAccount) {
                $managedAdmin->changeStatus(UserAccountStatus::Active);
            }

            $entityManager->flush();
        }
    }

    public function testSecurityReviewLinkRejectsInactiveAccount(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'inactiveforgotreview', 'current-password');
        $user->changeStatus(UserAccountStatus::Inactive);
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/security-review/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
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
        self::assertSelectorTextContains('.studio-form-errors', 'The new password must contain at least 8 characters.');
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
            [],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/invitation/'.$plainToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Accept invitation');
        self::assertSelectorExists('input[name="username"]');
    }

    public function testPasswordResetTokenRendersPasswordPolicyMeter(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'resetmeter', 'current-password');
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/reset-password/'.$plainToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choose new password');

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testExpiredInvitationTokenCannotBeAccepted(): void
    {
        $client = self::createClient();
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'expired-invitee@example.test',
            [],
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

    public function testSecurityReviewTokenCannotEnterInvitationFlow(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'reviewnotinvite', 'current-password');
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/invitation/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testInvitationTokenCannotEnterPasswordResetFlow(): void
    {
        $client = self::createClient();
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'invitation-not-reset@example.test',
            [],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/reset-password/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->flush();
    }

    public function testInvitationTokenCannotEnterSecurityReviewFlow(): void
    {
        $client = self::createClient();
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'invitation-not-review@example.test',
            [],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/security-review/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->flush();
    }

    public function testPasswordResetTokenCannotEnterInvitationFlow(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'resetnotinvite', 'current-password');
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/invitation/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testInvitationAcceptanceIgnoresMissingGroupsOnPost(): void
    {
        $client = self::createClient();
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'missing-group-invitee@example.test',
            ['deleted_between_get_and_post'],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/user/invitation/'.$plainToken);
        $client->submit($crawler->selectButton('Create account')->form([
            'username' => 'missinggroupinvitee',
            'password' => 'Invite1!Pass',
            'confirm_password' => 'Invite1!Pass',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'Your account is ready.');

        $user = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'missinggroupinvitee']);

        self::assertInstanceOf(UserAccount::class, $user);
        self::assertSame([], $this->userGroupIdentifiers($user));
        $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
        self::assertStringContainsString('account.link_stale_groups', $messageLog);
        self::assertStringContainsString('deleted_between_get_and_post', $messageLog);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($user);
        $entityManager->flush();
    }

    public function testInvitationAcceptanceRejectsTokensWhenEmailWasClaimedMeanwhile(): void
    {
        $client = self::createClient();
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'claimed-invitee@example.test',
            [],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $claimedUser = new UserAccount(
            '60000000-0000-0000-0000-'.substr(md5('claimedinvitee'), 0, 12),
            'claimedinvitee',
            'claimed-invitee@example.test',
            'pending',
        );
        $claimedUser->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($claimedUser, 'current-password'));
        $entityManager->persist($token);
        $entityManager->persist($claimedUser);
        $entityManager->flush();

        $crawler = $client->request('GET', '/user/invitation/'.$plainToken);
        $client->submit($crawler->selectButton('Create account')->form([
            'username' => 'claimednewuser',
            'password' => 'Invite1!Pass',
            'confirm_password' => 'Invite1!Pass',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-form-errors', 'Email address "claimed-invitee@example.test" is already assigned to another account.');
        self::assertNull($entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'claimednewuser']));

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $claimedUser->uid()));
        $entityManager->flush();
    }

    public function testInvitationAcceptanceRejectsGroupsAboveTokenRoleWithMessage(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('content_editors', AccessLevel::AUTHOR);
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'low-role-group-invitee@example.test',
            [$group->identifier()],
            role: UserRole::User,
        );
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/user/invitation/'.$plainToken);
        $client->submit($crawler->selectButton('Create account')->form([
            'username' => 'lowrolegroup',
            'password' => 'Invite1!Pass',
            'confirm_password' => 'Invite1!Pass',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-form-errors', 'This account setup link can no longer be used.');
        self::assertNull($entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'lowrolegroup']));

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(AclGroup::class, $group->uid()));
        $entityManager->flush();
    }

    public function testInvitationAcceptanceAppliesTokenRoleAndRoleGroupFloor(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $this->createGroup('content_authors', AccessLevel::AUTHOR);
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Invitation,
            'author-invitee@example.test',
            [$group->identifier()],
            role: UserRole::Author,
        );
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/user/invitation/'.$plainToken);
        $client->submit($crawler->selectButton('Create account')->form([
            'username' => 'authorinvitee',
            'password' => 'Invite1!Pass',
            'confirm_password' => 'Invite1!Pass',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'Your account is ready.');

        $user = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'authorinvitee']);

        self::assertInstanceOf(UserAccount::class, $user);
        self::assertSame(UserRole::Author, $user->role());
        self::assertSame(['content_authors'], $this->userGroupIdentifiers($user));

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($user);
        $entityManager->remove($entityManager->find(AclGroup::class, $group->uid()));
        $entityManager->flush();
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
            self::assertSelectorTextContains('.studio-auth-notice', 'If the address can be registered, an email with account setup instructions was created.');

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

    public function testAutoApprovalRegistrationForDeletedElevatedAccountRequiresReview(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $deletedUser = $this->createUserWithLevel(8, 'deletedregister', 'old-password');
        $deletedUser->changeStatus(UserAccountStatus::Deleted);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'https://example.test');
        $config->set('user.registration.mode', 'auto_approval');
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $deletedUser->email(),
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-notice', 'Your registration request will be reviewed. If it is approved, you will receive an email with account setup instructions.');

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => $deletedUser->email(),
                'type' => AccountTokenType::Registration,
                'status' => AccountTokenStatus::PendingApproval,
            ]);

            self::assertInstanceOf(AccountToken::class, $token);
            self::assertSame($deletedUser->uid(), $token->user()?->uid());
            self::assertSame(UserRole::Admin, $token->role());
            self::assertSame([], $token->groupIdentifiers());
            $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
            self::assertStringContainsString('account.registration.approval_requested', $messageLog);
            self::assertStringNotContainsString('https://example.test/user/invitation/', $messageLog);
            self::assertStringNotContainsString('account.registration.existing_account', $messageLog);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
            $config->set('user.registration.mode', 'disabled');
        }
    }

    public function testAutoApprovalRegistrationForDeletedNonUserRoleRequiresReview(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $deletedUser = $this->createUserWithLevel(3, 'deleteduserregister', 'old-password');
        $deletedUser->changeStatus(UserAccountStatus::Deleted);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'https://example.test');
        $config->set('user.registration.mode', 'auto_approval');
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $deletedUser->email(),
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-notice', 'Your registration request will be reviewed. If it is approved, you will receive an email with account setup instructions.');

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => $deletedUser->email(),
                'type' => AccountTokenType::Registration,
                'status' => AccountTokenStatus::PendingApproval,
            ]);

            self::assertInstanceOf(AccountToken::class, $token);
            self::assertSame($deletedUser->uid(), $token->user()?->uid());
            self::assertSame(UserRole::Author, $token->role());
            $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
            self::assertStringContainsString('account.registration.approval_requested', $messageLog);
            self::assertStringNotContainsString('https://example.test/user/invitation/', $messageLog);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
            $config->set('user.registration.mode', 'disabled');
        }
    }

    public function testAutoApprovalRegistrationForDeletedUserRoleCreatesReactivationToken(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $deletedUser = $this->createUserWithLevel(1, 'deletednormalregister', 'old-password');
        $deletedUser->changeStatus(UserAccountStatus::Deleted);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'https://example.test');
        $config->set('user.registration.mode', 'auto_approval');
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $deletedUser->email(),
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-notice', 'If the address can be registered, an email with account setup instructions was created.');

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => $deletedUser->email(),
                'type' => AccountTokenType::Registration,
                'status' => AccountTokenStatus::Pending,
            ]);

            self::assertInstanceOf(AccountToken::class, $token);
            self::assertSame($deletedUser->uid(), $token->user()?->uid());
            self::assertSame(UserRole::User, $token->role());
            $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
            self::assertStringContainsString('account.registration.link', $messageLog);
            self::assertStringContainsString('https://example.test/user/invitation/', $messageLog);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
            $config->set('user.registration.mode', 'disabled');
        }
    }

    public function testRegistrationUsesConfiguredDefaultAclGroup(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = new AclGroup(
            '00000000-0000-4000-8000-000000009901',
            'signup_default',
            ['en' => 'Signup Default'],
            AccessLevel::USER,
        );
        $entityManager->persist($group);
        $entityManager->flush();

        try {
            $config->set('user.registration.mode', 'auto_approval');
            $config->set('user.default_acl_group', 'signup_default');
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => 'configured-default@example.test',
            ]));

            self::assertResponseIsSuccessful();

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => 'configured-default@example.test',
                'type' => AccountTokenType::Registration,
            ]);

            self::assertInstanceOf(AccountToken::class, $token);
            self::assertSame(['signup_default'], $token->groupIdentifiers());
            $entityManager->remove($token);
        } finally {
            $config->set('user.registration.mode', 'disabled');
            $config->set('user.default_acl_group', '');
            $managedGroup = $entityManager->find(AclGroup::class, $group->uid());

            if ($managedGroup instanceof AclGroup) {
                $entityManager->remove($managedGroup);
            }

            $entityManager->flush();
        }
    }

    public function testRegistrationDefaultAclGroupIsOptional(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');

        try {
            $config->set('site.url', 'https://example.test');
            $config->set('user.registration.mode', 'auto_approval');
            $config->set('user.default_acl_group', 'missing_optional_default');
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => 'optional-default@example.test',
            ]));

            self::assertResponseIsSuccessful();

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => 'optional-default@example.test',
                'type' => AccountTokenType::Registration,
            ]);

            self::assertInstanceOf(AccountToken::class, $token);
            self::assertSame(UserRole::User, $token->role());
            self::assertSame([], $token->groupIdentifiers());
            $entityManager->remove($token);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
            $config->set('user.registration.mode', 'disabled');
            $config->set('user.default_acl_group', '');
            $entityManager->flush();
        }
    }

    public function testRegistrationShowsDeliveryErrorWhenSiteUrlIsInvalid(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $email = 'registration-delivery-error@example.test';
        $admin = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'admin']);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');

        self::assertInstanceOf(UserAccount::class, $admin);

        try {
            $config->set('site.url', 'not-a-url');
            $config->set('user.registration.mode', 'auto_approval');
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $email,
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-form-errors', 'The account email could not be created. Please try again later.');

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => $email,
                'type' => AccountTokenType::Registration,
            ]);

            self::assertNull($token);

            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $admin->email(),
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-form-errors', 'The account email could not be created. Please try again later.');

            $existingAccountToken = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => $admin->email(),
                'type' => AccountTokenType::Registration,
            ]);

            self::assertNull($existingAccountToken);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
            $config->set('user.registration.mode', 'disabled');
        }
    }

    public function testDeletedAccountTokenAcceptanceReactivatesSameUserAndResetsGroups(): void
    {
        $client = self::createClient();
        $deletedUser = $this->createUserWithLevel(8, 'deletedaccept', 'old-password');
        $deletedUid = $deletedUser->uid();
        $deletedUser->changeStatus(UserAccountStatus::Deleted);
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::Registration,
            $deletedUser->email(),
            [],
            $deletedUser,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/user/invitation/'.$plainToken);
        $client->submit($crawler->selectButton('Create account')->form([
            'username' => 'reactivatedaccept',
            'password' => 'NewPassword1!',
            'confirm_password' => 'NewPassword1!',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-auth-notice', 'Your account is ready.');

        $entityManager->clear();
        $reactivatedUser = $entityManager->find(UserAccount::class, $deletedUid);
        $usedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(UserAccount::class, $reactivatedUser);
        self::assertSame('reactivatedaccept', $reactivatedUser->username());
        self::assertSame(UserAccountStatus::Active, $reactivatedUser->status());
        self::assertSame([], $this->userGroupIdentifiers($reactivatedUser));
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($reactivatedUser, 'NewPassword1!'));
        self::assertInstanceOf(AccountToken::class, $usedToken);
        self::assertSame(AccountTokenStatus::Used, $usedToken->status());
        self::assertSame($deletedUid, $usedToken->user()?->uid());
    }

    public function testAdminApprovalRegistrationExplainsDelayedMail(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $config->set('user.registration.mode', 'admin_approval');
        $email = 'approval-copy@example.test';

        try {
            $crawler = $client->request('GET', '/user/register');
            $client->submit($crawler->selectButton('Request account')->form([
                'email' => $email,
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-auth-notice', 'Your registration request will be reviewed. If it is approved, you will receive an email with account setup instructions.');

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $token = $entityManager->getRepository(AccountToken::class)->findOneBy(['email' => $email]);

            if ($token instanceof AccountToken) {
                $entityManager->remove($token);
                $entityManager->flush();
            }
        } finally {
            $config->set('user.registration.mode', 'disabled');
        }
    }

    public function testPasswordResetRevokesPreviousPendingTokens(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'resetdedupe', 'current-password');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'https://example.test');
        $entityManager->getConnection()->update('user_account', ['email' => 'ResetDedupe@Example.TEST'], ['uid' => $user->uid()]);
        $entityManager->clear();
        $user = $entityManager->getRepository(UserAccount::class)->find($user->uid());
        self::assertInstanceOf(UserAccount::class, $user);
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        $crawler = $client->request('GET', '/user/reset-password');
        $client->submit($crawler->selectButton('Request reset link')->form([
            'email' => 'resetdedupe@example.test',
        ]));

        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/user/reset-password');
        $client->submit($crawler->selectButton('Request reset link')->form([
            'email' => 'RESETDEDUPE@EXAMPLE.TEST',
        ]));

        self::assertResponseIsSuccessful();

        $tokens = $entityManager->getRepository(AccountToken::class)->findBy([
            'email' => 'resetdedupe@example.test',
            'type' => AccountTokenType::PasswordReset,
        ]);
        $statuses = array_map(static fn (AccountToken $token): AccountTokenStatus => $token->status(), $tokens);
        $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));

        self::assertCount(2, $tokens);
        self::assertCount(1, array_filter($statuses, static fn (AccountTokenStatus $status): bool => AccountTokenStatus::Pending === $status));
        self::assertCount(1, array_filter($statuses, static fn (AccountTokenStatus $status): bool => AccountTokenStatus::Revoked === $status));
        self::assertStringContainsString('https://example.test/user/reset-password/', $messageLog);

        foreach ($tokens as $token) {
            $entityManager->remove($token);
        }

        $entityManager->flush();
        $config->set('site.url', (string) $originalSiteUrl);
    }

    public function testPasswordResetShowsDeliveryErrorWhenSiteUrlIsInvalid(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'resetdeliveryerror', 'current-password');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $config->set('site.url', 'not-a-url');

        try {
            $crawler = $client->request('GET', '/user/reset-password');
            $client->submit($crawler->selectButton('Request reset link')->form([
                'email' => $user->email(),
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-form-errors', 'The reset email could not be created. Please try again later.');

            $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
                'email' => $user->email(),
                'type' => AccountTokenType::PasswordReset,
            ]);

            self::assertNull($token);

            $crawler = $client->request('GET', '/user/reset-password');
            $client->submit($crawler->selectButton('Request reset link')->form([
                'email' => 'missing-reset-delivery@example.test',
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-form-errors', 'The reset email could not be created. Please try again later.');
        } finally {
            $config->set('site.url', (string) $originalSiteUrl);
        }
    }

    public function testPasswordResetRequestSkipsInactiveAccount(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'inactiveforgotreset', 'current-password');
        $user->changeStatus(UserAccountStatus::Inactive);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();

        $crawler = $client->request('GET', '/user/reset-password');
        $client->submit($crawler->selectButton('Request reset link')->form([
            'email' => $user->email(),
        ]));

        self::assertResponseIsSuccessful();
        self::assertNull($entityManager->getRepository(AccountToken::class)->findOneBy([
            'user' => $user,
            'type' => AccountTokenType::PasswordReset,
        ]));

        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testPasswordResetTokenRejectsInactiveAccount(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'inactivecompletereset', 'current-password');
        $user->changeStatus(UserAccountStatus::Inactive);
        [$token, $plainToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        $client->request('GET', '/user/reset-password/'.$plainToken);

        self::assertResponseStatusCodeSame(404);

        $entityManager->remove($entityManager->find(AccountToken::class, $token->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
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

    public function testRevokedApiKeyCannotBeRevealedFromDirectUrl(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'revokedreveal', 'current-password');
        $apiKey = $this->createApiKey($user, 'revokedreveal');
        $apiKey->revoke();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        $client->request('GET', '/user/api-keys/'.$apiKey->uid().'/reveal');

        self::assertResponseStatusCodeSame(404);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($entityManager->find(ApiKey::class, $apiKey->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testUserCanCloseOwnAccountAndRevokeCredentials(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'closeaccount', 'current-password');
        $apiKey = $this->createApiKey($user, 'closekey');
        [$resetToken] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::PasswordReset,
            $user->email(),
            [],
            $user,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($resetToken);
        $entityManager->flush();
        $config = self::getContainer()->get(Config::class);
        $originalRetention = $config->get('user.deleted_user_retention_days', 7);
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-message-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $config->set('user.deleted_user_retention_days', 21);

            $client->loginUser($user);
            $crawler = $client->request('GET', '/user/profile');
            self::assertSelectorTextContains('main', 'Start account closure from a separate confirmation page.');
            self::assertStringNotContainsString('you have 21 day(s) to restore the account', (string) $client->getResponse()->getContent());

            $crawler = $client->click($crawler->selectLink('Close account')->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'you have 21 day(s) to restore the account');

            $form = $crawler->filter('form[action="/user/profile/close"]')->form([
                'password' => 'current-password',
            ]);
            $form['confirm_close']->tick();
            $client->submit($form);

            self::assertResponseRedirects('/');

            $client->request('GET', '/user/profile');
            self::assertResponseStatusCodeSame(401);

            $entityManager->clear();
            $closedUser = $entityManager->find(UserAccount::class, $user->uid());
            $revokedApiKey = $entityManager->find(ApiKey::class, $apiKey->uid());
            $revokedToken = $entityManager->find(AccountToken::class, $resetToken->uid());

            self::assertInstanceOf(UserAccount::class, $closedUser);
            self::assertSame(UserAccountStatus::Deleted, $closedUser->status());
            self::assertInstanceOf(ApiKey::class, $revokedApiKey);
            self::assertSame(ApiKeyStatus::Revoked, $revokedApiKey->status());
            self::assertInstanceOf(AccountToken::class, $revokedToken);
            self::assertSame(AccountTokenStatus::Revoked, $revokedToken->status());
            $messageLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-message-*.log') ?: []));
            self::assertStringContainsString('account.closed', $messageLog);
            self::assertStringContainsString('retention_days', $messageLog);
            self::assertStringContainsString('21', $messageLog);
        } finally {
            $config->set('user.deleted_user_retention_days', (int) $originalRetention);
            $entityManager->clear();

            foreach ([$resetToken->uid() => AccountToken::class, $apiKey->uid() => ApiKey::class, $user->uid() => UserAccount::class] as $uid => $class) {
                $entity = $entityManager->find($class, $uid);

                if (null !== $entity) {
                    $entityManager->remove($entity);
                }
            }

            $entityManager->flush();
        }
    }

    public function testLastOwnerCannotCloseOwnAccount(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $admin);

        $changedUsers = [];
        $admin->changePassword($passwordHasher->hashPassword($admin, 'current-password'));

        foreach ($entityManager->getRepository(UserAccount::class)->findAll() as $user) {
            if ($user instanceof UserAccount && $user !== $admin && UserAccountStatus::Active === $user->status() && $user->accessLevel() >= AccessLevel::OWNER) {
                $user->changeStatus(UserAccountStatus::Inactive);
                $changedUsers[] = $user;
            }
        }

        $entityManager->flush();

        try {
            $client->loginUser($admin);
            $crawler = $client->request('GET', '/user/profile');
            $crawler = $client->click($crawler->selectLink('Close account')->link());
            $form = $crawler->filter('form[action="/user/profile/close"]')->form([
                'password' => 'current-password',
            ]);
            $form['confirm_close']->tick();
            $client->submit($form);

            self::assertResponseRedirects('/user/profile/close');
            $client->followRedirect();
            self::assertSelectorTextContains('.studio-alert-error', 'The last active owner account cannot be closed.');

            $entityManager->clear();
            $persistedAdmin = $entityManager->find(UserAccount::class, $admin->uid());

            self::assertInstanceOf(UserAccount::class, $persistedAdmin);
            self::assertSame(UserAccountStatus::Active, $persistedAdmin->status());
        } finally {
            $restoredAdmin = $entityManager->find(UserAccount::class, $admin->uid());

            if ($restoredAdmin instanceof UserAccount) {
                $restoredAdmin->changePassword($passwordHasher->hashPassword($restoredAdmin, (string) $_SERVER['APP_SECRET']));
            }

            foreach ($changedUsers as $changedUser) {
                $restoredUser = $entityManager->find(UserAccount::class, $changedUser->uid());

                if ($restoredUser instanceof UserAccount) {
                    $restoredUser->changeStatus(UserAccountStatus::Active);
                }
            }

            $entityManager->flush();
        }
    }

    private function createUserWithLevel(int $level, string $username, string $password): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus(UserAccountStatus::Active);
            $existingUser->changeRole(UserRole::fromAccessLevel($level));
            $existingUser->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($existingUser, $password));
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            '60000000-0000-0000-0000-'.substr(md5($username), 0, 12),
            $username,
            $username.'@example.test',
            'pending',
            role: UserRole::fromAccessLevel($level),
        );
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createApiKey(UserAccount $user, string $prefix): ApiKey
    {
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '63000000-0000-0000-0000-'.substr(md5($prefix.$user->uid()), 0, 12),
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

    private function createGroup(string $identifier, int $minRole): AclGroup
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingGroup = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if ($existingGroup instanceof AclGroup) {
            $existingGroup->changeMinRole($minRole);

            return $existingGroup;
        }

        $group = new AclGroup(
            '62000000-0000-0000-0000-'.substr(md5($identifier), 0, 12),
            $identifier,
            ['en' => ucfirst(str_replace('_', ' ', $identifier))],
            $minRole,
        );
        $entityManager->persist($group);

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
}
