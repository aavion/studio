<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiAdminOperationalControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testAdminOperationalEndpointsRequireApiKey(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/logs');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminOperationalEndpointsReturnBasicReadModels(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsadm');

        foreach ([
            '/api/v1/admin/backups',
            '/api/v1/admin/logs',
            '/api/v1/admin/operations',
            '/api/v1/admin/scheduler',
            '/api/v1/admin/statistics',
            '/api/v1/admin/themes',
        ] as $path) {
            $client->request('GET', $path, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful($path);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertArrayHasKey('data', $payload, $path);
        }
    }

    public function testOpenApiIncludesAdminOperationalEndpoints(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());

        foreach (['/admin/backups', '/admin/logs', '/admin/operations', '/admin/scheduler', '/admin/statistics', '/admin/themes'] as $path) {
            self::assertArrayHasKey($path, $payload['paths']);
        }
    }

    private function createPlainApiKey(string $prefix): string
    {
        $user = $this->createUserWithLevel(AccessLevel::ADMIN, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '6a000000-0000-7000-8000-'.substr(md5($prefix), 0, 12),
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
