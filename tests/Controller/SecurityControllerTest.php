<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Entity\UserAccount;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class SecurityControllerTest extends WebTestCase
{
    use AuthenticatedClientTrait;

    public function testLoginRouteRendersLoginForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sign in');
        self::assertSelectorExists('form#user-login-form[action="/user/login"][method="post"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorExists('input[name="captcha[provider]"][value="none"]');
        self::assertSelectorExists('input[name="captcha[fallback_rendered]"][value="1"]');
        self::assertSelectorExists('input[name="captcha[form_id]"][value="user-login-form"]');
        self::assertSelectorNotExists('input[name="captcha[status]"][value="skipped"]');
        self::assertSelectorNotExists('input[name="_auto_ban_recovery_token"]');
        self::assertSelectorTextContains('a[href="/user/reset-password"]', 'Forgot password?');
        self::assertSelectorNotExists('.system-frontend-error-reference');
        self::assertSelectorNotExists('a[href="/user/register"]');
    }

    public function testRecoveryLoginRouteRendersAutoBanRecoveryMarker(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/login?bypass=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/user/login"][method="post"]');
        self::assertSelectorExists('input[name="_auto_ban_recovery_token"]');
    }

    public function testLoginFormAuthenticatesUserAccount(): void
    {
        $client = self::createClient();
        $this->createUserWithLevel(8, 'loginadmin', 'correct-password');

        $crawler = $client->request('GET', '/user/login');
        $form = $crawler->selectButton('Sign in')->form([
            'username' => 'loginadmin',
            'password' => 'correct-password',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/');

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Admin dashboard');
    }

    public function testLogoutRouteRendersConfirmationWithoutEndingSession(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(8, 'logoutadmin', 'correct-password');

        $this->loginTestUser($client, $user);
        $client->request('GET', '/user/logout');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sign out');
        self::assertSelectorExists('form[action="/user/logout"][method="post"]');

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Admin dashboard');
    }

    public function testAuthenticatedNavigationRendersSafeLogoutLinkAttributes(): void
    {
        $client = self::createClient();
        $this->createUserWithLevel(8, 'logoutlinkadmin', 'correct-password');

        $crawler = $client->request('GET', '/user/login');
        $form = $crawler->selectButton('Sign in')->form([
            'username' => 'logoutlinkadmin',
            'password' => 'correct-password',
        ]);

        $client->submit($form);
        $client->request('GET', '/user/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/user/logout"][data-turbo="false"][data-turbo-prefetch="false"]');
    }

    public function testLogoutFormEndsSession(): void
    {
        $client = self::createClient();
        $this->createUserWithLevel(8, 'logoutformadmin', 'correct-password');

        $crawler = $client->request('GET', '/user/login');
        $form = $crawler->selectButton('Sign in')->form([
            'username' => 'logoutformadmin',
            'password' => 'correct-password',
        ]);

        $client->submit($form);

        $crawler = $client->request('GET', '/user/logout');
        $client->submit($crawler->selectButton('Sign out')->form());

        self::assertResponseRedirects('/');

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidLoginRendersTranslatedFeedback(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/user/login');
        $form = $crawler->selectButton('Sign in')->form([
            'username' => 'missinguser',
            'password' => 'wrong-password',
        ]);

        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('.system-frontend-auth-notice', 'The username or password is not valid.');
    }

    public function testLoginFormRejectsInactiveAndDeletedAccounts(): void
    {
        $client = self::createClient();
        $inactive = $this->createUserWithLevel(8, 'inactiveadmin', 'correct-password', UserAccountStatus::Inactive);
        $deleted = $this->createUserWithLevel(8, 'deletedadmin', 'correct-password', UserAccountStatus::Deleted);

        foreach ([$inactive, $deleted] as $user) {
            $crawler = $client->request('GET', '/user/login');
            $form = $crawler->selectButton('Sign in')->form([
                'username' => $user->username(),
                'password' => 'correct-password',
            ]);

            $client->submit($form);
            $client->followRedirect();

            self::assertSelectorTextContains('.system-frontend-auth-notice', 'The username or password is not valid.');

            $client->request('GET', '/admin');

            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testLoginRouteAllowsOnlyLocalReturnTargets(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/login?return_to=/admin');

        self::assertSelectorExists('input[name="_target_path"][value="/admin"]');

        foreach (['//example.test', '/\\example.test/path', "/admin\nLocation: https://example.test"] as $target) {
            $client->request('GET', '/user/login?return_to='.rawurlencode($target));

            self::assertSelectorNotExists('input[name="_target_path"]');
        }
    }

    public function testRegistrationRouteIsHiddenWhenRegistrationIsDisabled(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/register');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationRouteAndLoginLinkRenderWhenRegistrationIsEnabled(): void
    {
        $client = self::createClient();
        $this->setRegistrationMode('auto_approval');

        try {
            $client->request('GET', '/user/login');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/user/register"]');

            $client->request('GET', '/user/register');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Create account');
        } finally {
            $this->setRegistrationMode('disabled');
        }
    }

    public function testPublicPasswordResetSkeletonRouteRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reset password');
    }

    private function setRegistrationMode(string $mode): void
    {
        self::getContainer()->get(Config::class)->set('user.registration.mode', $mode);
    }

    private function createUserWithLevel(
        int $level,
        string $username,
        string $password,
        UserAccountStatus $status = UserAccountStatus::Active,
    ): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus($status);
            $existingUser->changeRole(UserRole::fromAccessLevel($level));
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            $this->testUserUid($username),
            $username,
            $username.'@example.test',
            'pending',
            status: $status,
            role: UserRole::fromAccessLevel($level),
        );
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function testUserUid(string $username): string
    {
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $username)->toRfc4122();
    }
}
