<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiUserControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testUsersRejectAnonymousAccess(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(401);
    }

    public function testUsersReturnFilteredUserListForAdminApiKeys(): void
    {
        $client = self::createClient();
        $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiusertarget', 'current-password');
        $plainKey = $this->createPlainApiKey('apiuseradm');

        $client->request('GET', '/api/v1/admin/users?q=apiusertarget&per_page=5', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(1, $payload['meta']['pagination']['total']);

        $user = $payload['data'][0];
        self::assertSame('user', $user['type']);
        self::assertSame('apiusertarget', $user['attributes']['username']);
        self::assertSame('author', $user['attributes']['role']);
        self::assertSame(AccessLevel::AUTHOR, $user['attributes']['access_level']);
    }

    public function testUserGroupAndReviewEndpointsReturnBasicListsForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiusrsub');

        foreach (['/api/v1/admin/users/groups', '/api/v1/admin/users/reviews'] as $path) {
            $client->request('GET', $path, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful($path);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertArrayHasKey('data', $payload, $path);
            self::assertArrayHasKey('pagination', $payload['meta'], $path);
        }
    }

    public function testOpenApiIncludesUsersEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/admin/users', $payload['paths']);
        self::assertArrayHasKey('/admin/users/groups', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews', $payload['paths']);
        self::assertSame('listUsers', $payload['paths']['/admin/users']['get']['operationId']);
        self::assertSame('listUserGroups', $payload['paths']['/admin/users/groups']['get']['operationId']);
        self::assertSame('listUserReviews', $payload['paths']['/admin/users/reviews']['get']['operationId']);
    }

    private function createPlainApiKey(string $prefix): string
    {
        $user = $this->createUserWithLevel(AccessLevel::ADMIN, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '69000000-0000-7000-8000-'.substr(md5($prefix), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $user,
            ApiKeyStatus::ReadOnly,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);
        $entityManager->flush();

        return $plainKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
