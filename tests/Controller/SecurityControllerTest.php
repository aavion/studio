<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Security\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginRouteRendersLoginForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sign in');
        self::assertSelectorExists('form[action="/user/login"][method="post"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorNotExists('.studio-error-reference');
        self::assertSelectorNotExists('a[href="/user/register"]');
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
        $this->createUserWithLevel(8, 'logoutadmin', 'correct-password');

        $crawler = $client->request('GET', '/user/login');
        $form = $crawler->selectButton('Sign in')->form([
            'username' => 'logoutadmin',
            'password' => 'correct-password',
        ]);

        $client->submit($form);
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

        self::assertSelectorTextContains('.studio-auth-notice', 'The username or password is not valid.');
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

            self::assertSelectorTextContains('.studio-auth-notice', 'The username or password is not valid.');

            $client->request('GET', '/admin');

            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testLoginRouteAllowsOnlyLocalReturnTargets(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/login?return_to=/admin');

        self::assertSelectorExists('input[name="_target_path"][value="/admin"]');

        $client->request('GET', '/user/login?return_to=//example.test');

        self::assertSelectorNotExists('input[name="_target_path"]');
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
        $this->setRegistrationEnabled(true);

        try {
            $client->request('GET', '/user/login');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/user/register"]');

            $client->request('GET', '/user/register');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Create account');
        } finally {
            $this->setRegistrationEnabled(false);
        }
    }

    public function testPublicPasswordResetSkeletonRouteRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/user/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reset password');
    }

    private function setRegistrationEnabled(bool $enabled): void
    {
        self::getContainer()->get(Config::class)->set('user.registration.enabled', $enabled);
    }

    private function createUserWithLevel(
        int $level,
        string $username,
        string $password,
        UserAccountStatus $status = UserAccountStatus::Active,
    ): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => $this->seededGroupIdentifier($level),
        ]);

        self::assertInstanceOf(AclGroup::class, $group);

        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus($status);
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            $this->testUserUid($username),
            $username,
            $username.'@example.test',
            'pending',
            status: $status,
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
            $level >= 3 => 'editor',
            default => 'registered',
        };
    }

    private function testUserUid(string $username): string
    {
        $hash = md5($username);

        return substr($hash, 0, 8)
            .'-'.substr($hash, 8, 4)
            .'-'.substr($hash, 12, 4)
            .'-'.substr($hash, 16, 4)
            .'-'.substr($hash, 20, 12);
    }
}
