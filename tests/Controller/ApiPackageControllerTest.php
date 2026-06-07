<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiPackageControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testPackagesRejectAnonymousAccess(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/packages');

        self::assertResponseStatusCodeSame(401);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.authentication_failed', $payload['error']['code']);
    }

    public function testPackageIndexAllowsPublicNavigation(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/packages');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_navigation', $payload['data']['type']);
        self::assertSame('/api/v1/packages', $payload['data']['attributes']['path']);
        self::assertSame(['GET'], array_column($payload['data']['attributes']['methods'], 'method'));
    }

    public function testPackagesReturnOverviewForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apipkgadmin');

        $client->request('GET', '/api/v1/admin/packages', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertGreaterThan(0, $payload['meta']['count']);

        $system = $this->resourceById($payload['data'], 'system');
        self::assertSame('package', $system['type']);
        self::assertSame('active', $system['attributes']['status']);
        self::assertArrayNotHasKey('actions', $system['attributes']);
        self::assertArrayNotHasKey('detail_path', $system['attributes']);
    }

    public function testOpenApiIncludesPackagesEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/packages', $payload['paths']);
        self::assertArrayHasKey('/admin/packages', $payload['paths']);
        self::assertSame('listPackageApiEndpoints', $payload['paths']['/packages']['get']['operationId']);
        self::assertSame([], $payload['paths']['/packages']['get']['security']);
        self::assertSame('listPackages', $payload['paths']['/admin/packages']['get']['operationId']);
    }

    private function createPlainApiKey(string $prefix): string
    {
        $user = $this->createUserWithLevel(AccessLevel::ADMIN, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '68000000-0000-7000-8000-'.substr(md5($prefix), 0, 12),
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
     * @param list<array<string, mixed>> $resources
     *
     * @return array<string, mixed>
     */
    private function resourceById(array $resources, string $id): array
    {
        foreach ($resources as $resource) {
            if (($resource['id'] ?? null) === $id) {
                return $resource;
            }
        }

        self::fail(sprintf('Resource "%s" was not returned.', $id));
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
