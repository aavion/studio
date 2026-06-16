<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Entity\ApiKey;
use App\Entity\ExtensionPackage;
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
        self::assertSame('system', $system['attributes']['package_name']);
        self::assertSame('system', $system['attributes']['package_slug']);
        self::assertSame('active', $system['attributes']['status']);
        self::assertArrayNotHasKey('actions', $system['attributes']);
        self::assertArrayNotHasKey('detail_path', $system['attributes']);
    }

    public function testPackageDetailReturnsDisabledLifecycleApiActionsForDelegatedAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apipkgdetail');
        $this->upsertPackage('api-package-detail', ExtensionPackageStatus::Inactive);

        $client->request('GET', '/api/v1/admin/packages/api-package-detail', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('package', $payload['data']['type']);
        self::assertSame('api-package-detail', $payload['data']['id']);
        self::assertSame('api-package-detail', $payload['data']['attributes']['package_name']);
        self::assertSame('api-package-detail', $payload['data']['attributes']['package_slug']);
        self::assertSame('/api/v1/admin/packages/api-package-detail', $payload['data']['attributes']['api_path']);
        $actions = array_column($payload['data']['attributes']['api_actions'], null, 'id');
        self::assertSame('/api/v1/admin/packages/api-package-detail/activate', $actions['activate']['api_path']);
        self::assertTrue($actions['activate']['disabled']);
    }

    public function testPackageDetailReturnsApiActionsForOwnerApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apipkgowner', accessLevel: AccessLevel::OWNER);
        $this->upsertPackage('api-package-owner', ExtensionPackageStatus::Inactive);

        $client->request('GET', '/api/v1/admin/packages/api-package-owner', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());

        $actions = array_column($payload['data']['attributes']['api_actions'], 'api_path', 'id');
        self::assertSame('/api/v1/admin/packages/api-package-owner/activate', $actions['activate']);
        self::assertSame('/api/v1/admin/packages/api-package-owner/delete', $actions['delete']);
    }

    public function testPackageLifecycleActionReturnsReviewUntilConfirmedForOwnerApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apipkgwrite', ApiKeyStatus::ReadWrite, AccessLevel::OWNER);
        $this->upsertPackage('api-package-action', ExtensionPackageStatus::Inactive);

        $client->request('POST', '/api/v1/admin/packages/api-package-action/activate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('package_lifecycle_review', $payload['data']['type']);
        self::assertSame('api-package-action:activate', $payload['data']['id']);
        self::assertSame('ok', $payload['data']['attributes']['status']);
        self::assertSame('confirm=true', $payload['data']['attributes']['confirm_parameter']);
    }

    public function testPackageLifecycleConfirmationRejectsDelegatedAdminApiKeysByDefault(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apipkgdenied', ApiKeyStatus::ReadWrite);
        $this->upsertPackage('api-package-denied', ExtensionPackageStatus::Inactive);

        $client->request('POST', '/api/v1/admin/packages/api-package-denied/activate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('package_lifecycle_review', $payload['data']['type']);

        $client->request('POST', '/api/v1/admin/packages/api-package-denied/activate?confirm=true', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api.operation_unavailable', $payload['error']['code']);
        self::assertSame('feature_read_only', $payload['error']['context']['reason']);
    }

    public function testPackageLifecycleActionRequiresWriteApiKey(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apipkgreadonly');
        $this->upsertPackage('api-package-readonly', ExtensionPackageStatus::Inactive);

        $client->request('POST', '/api/v1/admin/packages/api-package-readonly/activate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOpenApiIncludesPackagesEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/packages', $payload['paths']);
        self::assertArrayHasKey('/admin/packages', $payload['paths']);
        self::assertArrayHasKey('/admin/packages/{package_slug}', $payload['paths']);
        self::assertArrayHasKey('/admin/packages/{package_slug}/activate', $payload['paths']);
        self::assertSame('listPackageApiEndpoints', $payload['paths']['/packages']['get']['operationId']);
        self::assertSame(['packages-navigation'], $payload['paths']['/packages']['get']['tags']);
        self::assertSame([], $payload['paths']['/packages']['get']['security']);
        self::assertSame('listPackages', $payload['paths']['/admin/packages']['get']['operationId']);
        self::assertSame(['backend-admin', 'backend-admin-packages'], $payload['paths']['/admin/packages']['get']['tags']);
        self::assertSame('getPackage', $payload['paths']['/admin/packages/{package_slug}']['get']['operationId']);
        self::assertSame('packageActivate', $payload['paths']['/admin/packages/{package_slug}/activate']['post']['operationId']);
        self::assertContains([
            'name' => 'backend-admin-packages',
            'summary' => 'Backend Admin Packages',
            'description' => 'Administrative package management and lifecycle resources.',
            'parent' => 'backend-admin',
            'kind' => 'nav',
        ], $payload['tags']);
    }

    private function createPlainApiKey(
        string $prefix,
        ApiKeyStatus $status = ApiKeyStatus::ReadOnly,
        int $accessLevel = AccessLevel::ADMIN,
    ): string
    {
        $user = $this->createUserWithLevel($accessLevel, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '68000000-0000-7000-8000-'.substr(md5($prefix.$status->value.(string) $accessLevel), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $user,
            $status,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);
        $entityManager->flush();

        return $plainKey;
    }

    private function upsertPackage(string $packageName, ExtensionPackageStatus $status): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(ExtensionPackage::class)->findOneBy(['packageName' => $packageName]);
        if ($existing instanceof ExtensionPackage) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        $entityManager->persist(new ExtensionPackage(
            '69000000-0000-7000-8000-'.substr(md5($packageName), 0, 12),
            [PackageScope::Module],
            $packageName,
            'packages/'.$packageName,
            $status,
            [
                'display_name' => ucfirst(str_replace('-', ' ', $packageName)),
                'description' => 'API package fixture',
                'author' => 'Test Suite',
            ],
            manifestVersion: '1.0.0',
        ));
        $entityManager->flush();
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
