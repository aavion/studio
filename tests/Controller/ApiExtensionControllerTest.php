<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionScope;
use App\Entity\ApiKey;
use App\Entity\Extension;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiExtensionControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    private const TEST_EXTENSION_SLUGS = [
        'api-extension-action',
        'api-extension-denied',
        'api-extension-detail',
        'api-extension-owner',
        'api-extension-readonly',
    ];

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (self::TEST_EXTENSION_SLUGS as $extensionName) {
            $extension = $entityManager->getRepository(Extension::class)->findOneBy(['extensionName' => $extensionName]);
            if ($extension instanceof Extension) {
                $entityManager->remove($extension);
            }
        }

        $entityManager->flush();

        parent::tearDown();
    }

    public function testExtensionsRejectAnonymousAccess(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/extensions');

        self::assertResponseStatusCodeSame(401);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.authentication_failed', $payload['error']['code']);
    }

    public function testExtensionIndexAllowsPublicNavigation(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/extensions');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_navigation', $payload['data']['type']);
        self::assertSame('/api/v1/extensions', $payload['data']['attributes']['path']);
        self::assertSame(['GET'], array_column($payload['data']['attributes']['methods'], 'method'));
    }

    public function testExtensionsReturnOverviewForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiextadmin');

        $client->request('GET', '/api/v1/admin/extensions', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertGreaterThan(0, $payload['meta']['count']);

        $system = $this->resourceById($payload['data'], 'system');
        self::assertSame('extension', $system['type']);
        self::assertSame('system', $system['attributes']['extension_name']);
        self::assertSame('system', $system['attributes']['extension_slug']);
        self::assertSame('active', $system['attributes']['status']);
        self::assertArrayNotHasKey('actions', $system['attributes']);
        self::assertArrayNotHasKey('detail_path', $system['attributes']);
    }

    public function testExtensionDetailReturnsDisabledLifecycleApiActionsForDelegatedAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiextdetail');
        $this->upsertExtension('api-extension-detail', ExtensionStatus::Inactive);

        $client->request('GET', '/api/v1/admin/extensions/api-extension-detail', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('extension', $payload['data']['type']);
        self::assertSame('api-extension-detail', $payload['data']['id']);
        self::assertSame('api-extension-detail', $payload['data']['attributes']['extension_name']);
        self::assertSame('api-extension-detail', $payload['data']['attributes']['extension_slug']);
        self::assertSame('/api/v1/admin/extensions/api-extension-detail', $payload['data']['attributes']['api_path']);
        $actions = array_column($payload['data']['attributes']['api_actions'], null, 'id');
        self::assertSame('/api/v1/admin/extensions/api-extension-detail/activate', $actions['activate']['api_path']);
        self::assertTrue($actions['activate']['disabled']);
    }

    public function testExtensionDetailReturnsApiActionsForOwnerApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiextowner', accessLevel: AccessLevel::OWNER);
        $this->upsertExtension('api-extension-owner', ExtensionStatus::Inactive);

        $client->request('GET', '/api/v1/admin/extensions/api-extension-owner', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());

        $actions = array_column($payload['data']['attributes']['api_actions'], 'api_path', 'id');
        self::assertSame('/api/v1/admin/extensions/api-extension-owner/activate', $actions['activate']);
        self::assertSame('/api/v1/admin/extensions/api-extension-owner/delete', $actions['delete']);
    }

    public function testExtensionLifecycleActionReturnsReviewUntilConfirmedForOwnerApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiextwrite', ApiKeyStatus::ReadWrite, AccessLevel::OWNER);
        $this->upsertExtension('api-extension-action', ExtensionStatus::Inactive);

        $client->request('POST', '/api/v1/admin/extensions/api-extension-action/activate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('extension_lifecycle_review', $payload['data']['type']);
        self::assertSame('api-extension-action:activate', $payload['data']['id']);
        self::assertSame('ok', $payload['data']['attributes']['status']);
        self::assertSame('confirm=true', $payload['data']['attributes']['confirm_parameter']);
    }

    public function testExtensionLifecycleConfirmationRejectsDelegatedAdminApiKeysByDefault(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiextdenied', ApiKeyStatus::ReadWrite);
        $this->upsertExtension('api-extension-denied', ExtensionStatus::Inactive);

        $client->request('POST', '/api/v1/admin/extensions/api-extension-denied/activate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('extension_lifecycle_review', $payload['data']['type']);

        $client->request('POST', '/api/v1/admin/extensions/api-extension-denied/activate?confirm=true', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api.operation_unavailable', $payload['error']['code']);
        self::assertSame('feature_read_only', $payload['error']['context']['reason']);
    }

    public function testExtensionLifecycleActionRequiresWriteApiKey(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiexthreadonly');
        $this->upsertExtension('api-extension-readonly', ExtensionStatus::Inactive);

        $client->request('POST', '/api/v1/admin/extensions/api-extension-readonly/activate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOpenApiIncludesExtensionsEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/extensions', $payload['paths']);
        self::assertArrayHasKey('/admin/extensions', $payload['paths']);
        self::assertArrayHasKey('/admin/extensions/{extension_slug}', $payload['paths']);
        self::assertArrayHasKey('/admin/extensions/{extension_slug}/activate', $payload['paths']);
        self::assertSame('listExtensionApiEndpoints', $payload['paths']['/extensions']['get']['operationId']);
        self::assertSame(['extensions-navigation'], $payload['paths']['/extensions']['get']['tags']);
        self::assertSame([], $payload['paths']['/extensions']['get']['security']);
        self::assertSame('listExtensions', $payload['paths']['/admin/extensions']['get']['operationId']);
        self::assertSame(['backend-admin', 'backend-admin-extensions'], $payload['paths']['/admin/extensions']['get']['tags']);
        self::assertSame('getExtension', $payload['paths']['/admin/extensions/{extension_slug}']['get']['operationId']);
        self::assertSame('^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$', $payload['paths']['/admin/extensions/{extension_slug}']['get']['parameters'][0]['schema']['pattern']);
        self::assertSame(60, $payload['paths']['/admin/extensions/{extension_slug}']['get']['parameters'][0]['schema']['maxLength']);
        self::assertSame('extensionActivate', $payload['paths']['/admin/extensions/{extension_slug}/activate']['post']['operationId']);
        self::assertContains([
            'name' => 'backend-admin-extensions',
            'summary' => 'Backend Admin Extensions',
            'description' => 'Administrative extension management and lifecycle resources.',
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

    private function upsertExtension(string $extensionName, ExtensionStatus $status): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(Extension::class)->findOneBy(['extensionName' => $extensionName]);
        if ($existing instanceof Extension) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        $entityManager->persist(new Extension(
            '69000000-0000-7000-8000-'.substr(md5($extensionName), 0, 12),
            [ExtensionScope::Module],
            $extensionName,
            'extensions/'.$extensionName,
            $status,
            [
                'display_name' => ucfirst(str_replace('-', ' ', $extensionName)),
                'description' => 'API extension fixture',
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
