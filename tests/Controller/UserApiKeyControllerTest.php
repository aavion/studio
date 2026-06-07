<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Api\ApiFeaturePolicy;
use App\Core\Config\Config;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserApiKeyControllerTest extends WebTestCase
{
    use AuthenticatedClientTrait;
    use UserControllerFixtureTrait;

    public function testApiKeysRouteListsPersistedKeysForTheCurrentUser(): void
    {
        $client = self::createClient();
        $user = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserAccount::class)
            ->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $user);

        $this->loginTestUser($client, $user);
        $client->request('GET', '/user/api-keys');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API keys');
        self::assertStringContainsString('seedrw', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Read and write', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('seedro', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Read only', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('seedrv', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Revoked', (string) $client->getResponse()->getContent());
    }

    public function testApiKeysCanBeCreatedRevealedAndRevoked(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'apikeyflow', 'current-password');
        $this->loginTestUser($client, $user);

        $crawler = $client->request('GET', '/user/api-keys');
        $form = $crawler->selectButton('Generate key')->form([
            'prefix' => 'flowkey',
            'read_only' => '1',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Generated API key', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('flowkey.', (string) $client->getResponse()->getContent());

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
        self::assertStringContainsString('flowkey.', (string) $client->getResponse()->getContent());

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
        $this->loginTestUser($client, $user);

        $client->request('GET', '/user/api-keys/'.$apiKey->uid().'/reveal');

        self::assertResponseStatusCodeSame(404);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($entityManager->find(ApiKey::class, $apiKey->uid()));
        $entityManager->remove($entityManager->find(UserAccount::class, $user->uid()));
        $entityManager->flush();
    }

    public function testDisabledApiHidesKeyManagementForNonOwners(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(1, 'disabledapiuser', 'current-password');
        self::getContainer()->get(Config::class)->set(ApiFeaturePolicy::ENABLED_KEY, false);
        $this->loginTestUser($client, $user);

        try {
            $client->request('GET', '/user/profile');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('/user/api-keys', (string) $client->getResponse()->getContent());

            $client->request('GET', '/user/api-keys');
            self::assertResponseStatusCodeSame(404);
        } finally {
            self::getContainer()->get(Config::class)->set(ApiFeaturePolicy::ENABLED_KEY, true);
        }
    }

    public function testDisabledApiKeepsKeyManagementAvailableForOwners(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(9, 'disabledapiowner', 'current-password');
        $user->changeRole(UserRole::Owner);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::getContainer()->get(Config::class)->set(ApiFeaturePolicy::ENABLED_KEY, false);
        $this->loginTestUser($client, $user);

        try {
            $client->request('GET', '/user/api-keys');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'API keys');
        } finally {
            self::getContainer()->get(Config::class)->set(ApiFeaturePolicy::ENABLED_KEY, true);
        }
    }
}
