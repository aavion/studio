<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiSettingsControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testSettingsRejectAnonymousAccess(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/settings');

        self::assertResponseStatusCodeSame(401);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.authentication_failed', $payload['error']['code']);
    }

    public function testSettingsRejectNonAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apisetuser', AccessLevel::USER);

        $client->request('GET', '/api/v1/admin/settings', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_key.permission_denied', $payload['error']['code']);
        self::assertSame(AccessLevel::ADMIN, $payload['error']['context']['required_access_level']);
    }

    public function testSettingsReturnsAdministrativeSettingsForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apisetadmin', AccessLevel::ADMIN);

        $client->request('GET', '/api/v1/admin/settings', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertGreaterThan(0, $payload['meta']['count']);

        $general = $this->resourceById($payload['data'], 'general');
        self::assertSame('settings_section', $general['type']);
        self::assertSame('/api/v1/admin/settings/general', $general['attributes']['path']);
        self::assertGreaterThan(0, $general['attributes']['field_count']);
    }

    public function testSettingsSectionReturnsAdministrativeFieldsForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apisetsec', AccessLevel::ADMIN);

        $client->request('GET', '/api/v1/admin/settings/general', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('general', $payload['meta']['section']);

        $siteTitle = $this->resourceById($payload['data'], 'site.title');
        self::assertSame('setting', $siteTitle['type']);
        self::assertSame('general', $siteTitle['attributes']['section']);
        self::assertSame('string', $siteTitle['attributes']['value_type']);
        self::assertSame('admin.settings.fields.site_title.label', $siteTitle['attributes']['label_key']);
    }

    public function testSettingsSectionCanBePatchedWithReadWriteAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadWrite, 'apisetpatch', AccessLevel::ADMIN);

        $client->request('PATCH', '/api/v1/admin/settings/general', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'values' => [
                'site.footer_copyright' => 'API test footer',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(['site.footer_copyright'], $payload['meta']['updated_keys']);

        $footer = $this->resourceById($payload['data'], 'site.footer_copyright');
        self::assertSame('API test footer', $footer['attributes']['value']);
    }

    public function testSettingsPatchPreservesSensitiveValuesWhenClientEchoesProtectedPlaceholder(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadWrite, 'apisetsecret', AccessLevel::OWNER);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'stored-api-secret', ConfigValueType::String, sensitive: true);

        try {
            $client->request('PATCH', '/api/v1/admin/settings/statistics', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode([
                'values' => [
                    MaxMindGeoIpConfig::ENABLED_KEY => true,
                    MaxMindGeoIpConfig::LICENSE_KEY_KEY => '[protected]',
                ],
            ], JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            self::assertSame('stored-api-secret', $config->get(MaxMindGeoIpConfig::LICENSE_KEY_KEY));
        } finally {
            $this->removeApiKeyUser('apisetsecret');
        }
    }

    public function testGeoIpSettingsAreHiddenAndRejectedForDelegatedAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadWrite, 'apisetgeoipadmin', AccessLevel::ADMIN);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'stored-api-secret', ConfigValueType::String, sensitive: true);

        $client->request('GET', '/api/v1/admin/settings/statistics', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        $licenseKey = $this->resourceById($payload['data'], MaxMindGeoIpConfig::LICENSE_KEY_KEY);
        self::assertSame('[protected]', $licenseKey['attributes']['value']);
        self::assertTrue($licenseKey['attributes']['metadata']['read_only']);

        $client->request('PATCH', '/api/v1/admin/settings/statistics', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'values' => [
                MaxMindGeoIpConfig::LICENSE_KEY_KEY => 'delegated-admin-secret',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('stored-api-secret', $config->get(MaxMindGeoIpConfig::LICENSE_KEY_KEY));
    }

    public function testSecuritySettingsSectionAclHidesAndRejectsSecurityFieldsForDelegatedAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadWrite, 'apisetsecadm', AccessLevel::ADMIN);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set('security.captcha.enabled', true, ConfigValueType::Boolean);

        $client->request('GET', '/api/v1/admin/settings/security', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(404);

        $client->request('PATCH', '/api/v1/admin/settings/security', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'values' => [
                'security.captcha.enabled' => false,
                RateLimitPolicyCatalogue::MODE_KEY => RateLimitProfile::Panic->value,
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
        self::assertTrue($config->get('security.captcha.enabled'));
    }

    public function testSecuritySettingsCanBeReadAndPatchedByOwnerApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadWrite, 'apisetsecown', AccessLevel::OWNER);

        try {
            $client->request('PATCH', '/api/v1/admin/settings/security', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode([
                'values' => [
                    'security.captcha.enabled' => true,
                    RateLimitPolicyCatalogue::MODE_KEY => RateLimitProfile::Strict->value,
                ],
            ], JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertContains('security.captcha.enabled', $payload['meta']['updated_keys']);
            self::assertContains(RateLimitPolicyCatalogue::MODE_KEY, $payload['meta']['updated_keys']);
        } finally {
            $this->removeApiKeyUser('apisetsecown');
        }
    }

    public function testSettingsPatchReturnsValidationErrors(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadWrite, 'apisetbad', AccessLevel::ADMIN);

        $client->request('PATCH', '/api/v1/admin/settings/general', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'values' => [
                'site.title' => '',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api.validation_failed', $payload['error']['code']);
        self::assertSame(['admin.settings.form.errors.required'], $payload['error']['context']['errors']['site.title']);
    }

    public function testSettingsPatchRequiresWriteApiKey(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey(ApiKeyStatus::ReadOnly, 'apisetro', AccessLevel::ADMIN);

        $client->request('PATCH', '/api/v1/admin/settings/general', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'values' => [
                'site.footer_copyright' => 'blocked',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOpenApiIncludesSettingsEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/admin/settings', $payload['paths']);
        self::assertArrayHasKey('/admin/settings/{section}', $payload['paths']);
        self::assertSame('listSettingsSections', $payload['paths']['/admin/settings']['get']['operationId']);
        self::assertSame(['backend-admin', 'backend-admin-settings'], $payload['paths']['/admin/settings']['get']['tags']);
        self::assertSame('listSettingsSection', $payload['paths']['/admin/settings/{section}']['get']['operationId']);
        self::assertSame('updateSettingsSection', $payload['paths']['/admin/settings/{section}']['patch']['operationId']);
        self::assertArrayNotHasKey('security', $payload['paths']['/admin/settings']['get']);
        self::assertContains([
            'name' => 'backend-admin-settings',
            'summary' => 'Backend Admin Settings',
            'description' => 'Administrative settings sections and values.',
            'parent' => 'backend-admin',
            'kind' => 'nav',
        ], $payload['tags']);
    }

    private function createPlainApiKey(ApiKeyStatus $status, string $prefix, int $accessLevel): string
    {
        $user = $this->createUserWithLevel($accessLevel, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '67000000-0000-7000-8000-'.substr(md5($prefix.$status->value.(string) $accessLevel), 0, 12),
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

    private function removeApiKeyUser(string $prefix): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM api_key WHERE prefix = ?', [$prefix]);
        $connection->executeStatement('DELETE FROM user_account WHERE username = ?', [$prefix.'user']);
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
     * @param list<array<string, mixed>> $resources
     *
     * @return array<string, mixed>|null
     */
    private function optionalResourceById(array $resources, string $id): ?array
    {
        foreach ($resources as $resource) {
            if (($resource['id'] ?? null) === $id) {
                return $resource;
            }
        }

        return null;
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
