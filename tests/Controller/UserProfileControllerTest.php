<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserProfileControllerTest extends WebTestCase
{
    use AuthenticatedClientTrait;
    use UserControllerFixtureTrait;

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
        $this->loginTestUser($client, $this->createUserWithLevel(1, 'indexuser', 'index-password'));
        $client->request('GET', '/user');

        self::assertResponseRedirects('/user/profile');
    }

    public function testProfileRouteRendersAccountSkeleton(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->createUserWithLevel(1, 'profileuser', 'profile-password'));
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

        $this->loginTestUser($client, $user);
        $crawler = $client->request('GET', '/user/profile');
        $client->request('POST', '/user/profile', [
            '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'username' => 'ChangedProfile',
            'email' => $user->email(),
            'display_name' => 'Stable Profile',
            'language' => 'default',
        ]);

        self::assertResponseRedirects('/user/profile');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame('stableprofile', $unchangedUser->username());
    }

    public function testProfileLanguageCanBeChangedAndAppliesToCurrentResponse(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'languageprofile', 'profile-password');

        $this->loginTestUser($client, $user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => $user->email(),
            'display_name' => 'Language Profile',
            'language' => 'de',
        ]));

        self::assertResponseRedirects('/user/profile');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Profil gespeichert.', (string) $client->getResponse()->getContent());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame('de', $updatedUser->settings()['language'] ?? null);

        $client->request('GET', '/user/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Profil');
    }

    public function testProfileLanguageRejectsUnsupportedSubmittedLocale(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'invalidlanguageprofile', 'profile-password');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user->updateSettings([]);
        $entityManager->flush();

        $this->loginTestUser($client, $user);
        $crawler = $client->request('GET', '/user/profile');
        $client->request('POST', '/user/profile', [
            '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'email' => $user->email(),
            'display_name' => 'Invalid Language Profile',
            'language' => 'fr',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Choose one of the available languages.', (string) $client->getResponse()->getContent());

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertArrayNotHasKey('language', $unchangedUser->settings());
    }

    public function testProfileUsernameCanBeChangedWhenSettingIsEnabled(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $user = $this->createUserWithLevel(1, 'renameprofile', 'profile-password');
        $config->set('user.username_change.enabled', true);

        try {
            $this->loginTestUser($client, $user);
            $crawler = $client->request('GET', '/user/profile');

            self::assertSelectorExists('input[name="username"]');

            $client->submit($crawler->selectButton('Save profile')->form([
                'username' => 'Renamed_Profile',
                'email' => $user->email(),
                'display_name' => 'Renamed Profile',
                'language' => 'default',
            ]));

            self::assertResponseRedirects('/user/profile');
            $client->followRedirect();

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Profile saved.', (string) $client->getResponse()->getContent());

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
            $this->loginTestUser($client, $user);
            $crawler = $client->request('GET', '/user/profile');
            $client->submit($crawler->selectButton('Save profile')->form([
                'username' => 'takenprofile',
                'email' => $user->email(),
                'display_name' => 'Duplicate Profile',
                'language' => 'default',
            ]));

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('This username is already used.', (string) $client->getResponse()->getContent());

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

        $this->loginTestUser($client, $user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => 'changed-emailprofile@example.test',
            'display_name' => 'Email Profile',
            'language' => 'default',
        ]));

        self::assertResponseRedirects('/user/profile');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Profile saved.', (string) $client->getResponse()->getContent());

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

        $this->loginTestUser($client, $user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => 'changed-emailrecovery@example.test',
            'display_name' => 'Email Recovery',
            'language' => 'default',
        ]));

        self::assertResponseRedirects('/user/profile');

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

        $this->loginTestUser($client, $user);
        $crawler = $client->request('GET', '/user/profile');
        $client->submit($crawler->selectButton('Save profile')->form([
            'email' => $taken->email(),
            'display_name' => 'Email Duplicate',
            'language' => 'default',
        ]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This email address is already used.', (string) $client->getResponse()->getContent());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame('emailduplicate@example.test', $unchangedUser->email());
    }
}
