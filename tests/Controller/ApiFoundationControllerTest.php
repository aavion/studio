<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Database\DatabaseReadyState;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Setup\SetupCompletionMarker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiFoundationControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testRootListsPublicTopLevelApiNavigation(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_navigation', $payload['data']['type']);
        self::assertSame('/api/v1', $payload['data']['attributes']['path']);

        $paths = array_map(
            static fn (array $resource): string => $resource['attributes']['path'],
            $payload['data']['attributes']['children'],
        );

        self::assertContains('/api/v1/status', $paths);
        self::assertContains('/api/v1/openapi.json', $paths);
        self::assertContains('/api/v1/content', $paths);
        self::assertContains('/api/v1/packages', $paths);
        self::assertNotContains('/api/v1/admin', $paths);
        self::assertNotContains('/api/v1/user', $paths);
    }

    public function testRootListsPrivateTopLevelApiNavigationForApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apirootnav', AccessLevel::ADMIN);

        $client->request('GET', '/api/v1', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        $paths = array_map(
            static fn (array $resource): string => $resource['attributes']['path'],
            $payload['data']['attributes']['children'],
        );

        self::assertContains('/api/v1/admin', $paths);
        self::assertContains('/api/v1/user', $paths);
        self::assertContains('/api/v1/schemas', $paths);
    }

    public function testStatusAllowsPublicReadAccess(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/status');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_status', $payload['data']['type']);
        self::assertSame('ok', $payload['data']['attributes']['status']);
    }

    public function testStatusReturnsJsonServiceUnavailableBeforeSetupIsComplete(): void
    {
        $setupState = $this->setEnvironment(SetupCompletionMarker::KEY, '0');
        $unreadyState = $this->setEnvironment(DatabaseReadyState::ALLOW_UNREADY_KEY, '0');

        try {
            self::ensureKernelShutdown();
            $client = self::createClient();
            $client->request('GET', '/api/v1/status');

            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertSame('60', $client->getResponse()->headers->get('Retry-After'));
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('api.unavailable_setup_incomplete', $payload['error']['code']);
            self::assertSame('setup_incomplete', $payload['error']['context']['reason']);
        } finally {
            $this->restoreEnvironment(SetupCompletionMarker::KEY, $setupState);
            $this->restoreEnvironment(DatabaseReadyState::ALLOW_UNREADY_KEY, $unreadyState);
            self::ensureKernelShutdown();
        }
    }

    public function testStatusReturnsJsonServiceUnavailableDuringMaintenanceForPublicRequests(): void
    {
        $maintenanceState = $this->setEnvironment('APP_MAINTENANCE', '1');

        try {
            self::ensureKernelShutdown();
            $client = self::createClient();
            $client->request('GET', '/api/v1/status');

            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertSame('60', $client->getResponse()->headers->get('Retry-After'));
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('api.unavailable_maintenance', $payload['error']['code']);
            self::assertSame('maintenance', $payload['error']['context']['reason']);
        } finally {
            $this->restoreEnvironment('APP_MAINTENANCE', $maintenanceState);
            self::ensureKernelShutdown();
        }
    }

    public function testStatusAllowsAdminApiKeyDuringMaintenance(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apimaintad', AccessLevel::ADMIN);
        $maintenanceState = $this->setEnvironment('APP_MAINTENANCE', '1');

        try {
            self::ensureKernelShutdown();
            $client = self::createClient();
            $client->request('GET', '/api/v1/status', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('api_status', $payload['data']['type']);
        } finally {
            $this->restoreEnvironment('APP_MAINTENANCE', $maintenanceState);
            self::ensureKernelShutdown();
        }
    }

    public function testStatusRejectsInvalidBearerApiKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/status', ['language' => 'de'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer missing.invalid',
        ]);

        self::assertResponseStatusCodeSame(401);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.authentication_failed', $payload['error']['code']);
        self::assertSame('API-Key-Authentifizierung fehlgeschlagen.', $payload['error']['message']);
    }

    public function testStatusRejectsMalformedBearerCredentials(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer realm="Studio API"', $client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testStatusIgnoresNonBearerAuthorizationOnPublicReads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Basic unrelated',
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_status', $payload['data']['type']);
    }

    public function testPrivateEndpointStillChallengesNonBearerAuthorization(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/admin', server: [
            'HTTP_AUTHORIZATION' => 'Basic unrelated',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer realm="Studio API"', $client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testStatusAcceptsReadOnlyBearerApiKey(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apistatusro');

        $client->request('GET', '/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_status', $payload['data']['type']);
        self::assertSame('ok', $payload['data']['attributes']['status']);
    }

    public function testStatusRejectsRevokedBearerApiKey(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::Revoked, 'apistatusrv');

        $client->request('GET', '/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(401);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.permission_revoked', $payload['error']['code']);
        self::assertSame('message.api_key.permission.revoked', $payload['error']['message_key']);
    }

    public function testOpenApiDocumentIsGeneratedFromRegisteredEndpointDefinitions(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('3.2.0', $payload['openapi']);
        self::assertSame('/api/v1/openapi.json', $payload['$self']);
        self::assertSame('Studio API', $payload['info']['title']);
        self::assertSame('Symfony 8.1 based content-management system for structured project websites.', $payload['info']['description']);
        self::assertSame(['name' => 'MIT', 'identifier' => 'MIT'], $payload['info']['license']);
        self::assertSame([['name' => 'current', 'url' => '/api/v1']], $payload['servers']);
        self::assertContains(['name' => 'system-status', 'summary' => 'System Status', 'description' => 'Status and healthcheck resources.', 'kind' => 'nav'], $payload['tags']);
        self::assertArrayHasKey('/', $payload['paths']);
        self::assertArrayHasKey('/status', $payload['paths']);
        self::assertArrayHasKey('/openapi.json', $payload['paths']);
        self::assertArrayHasKey('/admin', $payload['paths']);
        self::assertArrayHasKey('/admin/permissions', $payload['paths']);
        self::assertSame('getApiRoot', $payload['paths']['/']['get']['operationId']);
        self::assertSame('getApiStatus', $payload['paths']['/status']['get']['operationId']);
        self::assertSame([], $payload['paths']['/status']['get']['security']);
        self::assertSame(8, $payload['paths']['/admin/permissions']['get']['x-access']['required_access_level']);
        self::assertSame('read_only_or_read_write', $payload['paths']['/admin/permissions']['get']['x-access']['key_capability']);
    }

    public function testAdminEndpointIndexRejectsAnonymousAccess(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin');

        self::assertResponseStatusCodeSame(401);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.authentication_failed', $payload['error']['code']);
    }

    public function testAdminEndpointIndexRejectsNonAdminApiKeysBeforeHandlerExecution(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apiadmus', AccessLevel::USER);

        $client->request('GET', '/api/v1/admin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.permission_denied', $payload['error']['code']);
        self::assertSame(AccessLevel::ADMIN, $payload['error']['context']['required_access_level']);
        self::assertSame('listAdminApiEndpoints', $payload['error']['context']['operation_id']);
    }

    public function testAdminEndpointIndexListsAdministrativeEndpointsForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apiadminix', AccessLevel::ADMIN);

        $client->request('GET', '/api/v1/admin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_navigation', $payload['data']['type']);
        self::assertSame('/api/v1/admin', $payload['data']['attributes']['path']);

        $paths = array_map(
            static fn (array $resource): string => $resource['attributes']['path'],
            $payload['data']['attributes']['children'],
        );

        self::assertContains('/api/v1/admin/settings', $paths);
        self::assertContains('/api/v1/admin/packages', $paths);
        self::assertContains('/api/v1/admin/users', $paths);
        self::assertContains('/api/v1/admin/permissions', $paths);
        self::assertNotContains('/api/v1/status', $paths);

        $permissionsChild = array_values(array_filter(
            $payload['data']['attributes']['children'],
            static fn (array $resource): bool => '/api/v1/admin/permissions' === $resource['attributes']['path'],
        ))[0] ?? null;

        self::assertIsArray($permissionsChild);
        self::assertSame(8, $permissionsChild['attributes']['methods'][0]['access']['required_access_level']);
    }

    public function testAdminPermissionMatrixListsEndpointAccessRequirements(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apipermix', AccessLevel::ADMIN);

        $client->request('GET', '/api/v1/admin/permissions', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertGreaterThan(0, $payload['meta']['count']);

        $permissions = [];
        foreach ($payload['data'] as $resource) {
            $permissions[$resource['attributes']['method'].' '.$resource['attributes']['path']] = $resource['attributes'];
        }

        self::assertArrayHasKey('GET /api/v1/status', $permissions);
        self::assertFalse($permissions['GET /api/v1/status']['requires_api_key']);
        self::assertSame('public', $permissions['GET /api/v1/status']['required_role']);
        self::assertArrayHasKey('PATCH /api/v1/admin/settings/{section}', $permissions);
        self::assertSame('admin', $permissions['PATCH /api/v1/admin/settings/{section}']['required_role']);
        self::assertSame('read_write', $permissions['PATCH /api/v1/admin/settings/{section}']['key_capability']);
    }

    private function createPlainApiKey(ApiKeyStatus $status, string $prefix, int $accessLevel = 1): string
    {
        $user = $this->createUserWithLevel($accessLevel, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '64000000-0000-7000-8000-'.substr(md5($prefix.$status->value), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $user,
            $status,
        );

        if (ApiKeyStatus::Revoked === $status) {
            $apiKey->revoke();
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);
        $entityManager->flush();

        return $plainKey;
    }

    /**
     * @return array{server_exists: bool, server: mixed, env_exists: bool, env: mixed, getenv: string|false}
     */
    private function setEnvironment(string $key, string $value): array
    {
        $previous = [
            'server_exists' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
            'env_exists' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'getenv' => getenv($key),
        ];

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);

        return $previous;
    }

    /**
     * @param array{server_exists: bool, server: mixed, env_exists: bool, env: mixed, getenv: string|false} $previous
     */
    private function restoreEnvironment(string $key, array $previous): void
    {
        if ($previous['server_exists']) {
            $_SERVER[$key] = $previous['server'];
        } else {
            unset($_SERVER[$key]);
        }

        if ($previous['env_exists']) {
            $_ENV[$key] = $previous['env'];
        } else {
            unset($_ENV[$key]);
        }

        if (false === $previous['getenv']) {
            putenv($key);

            return;
        }

        putenv($key.'='.$previous['getenv']);
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
