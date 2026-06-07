<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
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

    public function testOpenApiIncludesSettingsEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/admin/settings', $payload['paths']);
        self::assertArrayHasKey('/admin/settings/{section}', $payload['paths']);
        self::assertSame('listSettingsSections', $payload['paths']['/admin/settings']['get']['operationId']);
        self::assertSame('listSettingsSection', $payload['paths']['/admin/settings/{section}']['get']['operationId']);
        self::assertArrayNotHasKey('security', $payload['paths']['/admin/settings']['get']);
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
