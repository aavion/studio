<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\DBAL\Connection;
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
        self::getContainer()->get(Connection::class)->update(
            'config_entry',
            ['value' => json_encode($enabled, JSON_THROW_ON_ERROR), 'value_type' => 'boolean'],
            ['config_key' => 'user.registration.enabled'],
        );
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
            '40000000-0000-0000-0000-00000000000'.$level,
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
            $level >= 3 => 'editor',
            default => 'registered',
        };
    }
}
