<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessLevel;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\ContentItem;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiContentSchemaControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testContentIndexAllowsPublicNavigation(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/content');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api_navigation', $payload['data']['type']);
        self::assertSame('/api/v1/content', $payload['data']['attributes']['path']);
    }

    public function testContentItemsReturnPublicPublishedMetadata(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeExistingContentItem($entityManager, 'api-content-item');
        $item = new ContentItem('6b000000-0000-7000-8000-000000000001', 'api-content-item');
        $item->setAvailableLanguages(['en']);
        $item->publish();
        $entityManager->persist($item);
        $entityManager->flush();

        $client->request('GET', '/api/v1/content/items');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        $resource = $this->resourceById($payload['data'], 'api-content-item');
        self::assertSame('content_item', $resource['type']);
        self::assertSame('published', $resource['attributes']['status']);
    }

    public function testContentItemsRespectViewAccessRules(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeExistingContentItem($entityManager, 'api-author-content');

        $item = new ContentItem('6b000000-0000-7000-8000-000000000002', 'api-author-content');
        $item->setAvailableLanguages(['en']);
        $item->setViewRule(AccessLevel::AUTHOR);
        $item->publish();
        $entityManager->persist($item);
        $entityManager->flush();

        $client->request('GET', '/api/v1/content/items');
        self::assertResponseIsSuccessful();
        $anonymousPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertFalse($this->hasResource($anonymousPayload['data'], 'api-author-content'));

        $plainKey = $this->createPlainApiKey('apicontent', AccessLevel::AUTHOR);
        $client->request('GET', '/api/v1/content/items', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $authenticatedPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertTrue($this->hasResource($authenticatedPayload['data'], 'api-author-content'));
    }

    public function testContentItemsReuseGroupAwareContentAccessRules(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeExistingContentItem($entityManager, 'api-group-or-content');
        $this->removeExistingContentItem($entityManager, 'api-group-and-content');

        $orGroup = $this->createGroup('api_content_viewers', AccessLevel::PUBLIC);
        $andGroup = $this->createGroup('api_restricted_viewers', AccessLevel::PUBLIC);

        $orContent = new ContentItem('6b000000-0000-7000-8000-000000000003', 'api-group-or-content');
        $orContent->setAvailableLanguages(['en']);
        $orContent->setViewRule(AccessLevel::AUTHOR, [$orGroup->identifier()]);
        $orContent->publish();

        $andContent = new ContentItem('6b000000-0000-7000-8000-000000000004', 'api-group-and-content');
        $andContent->setAvailableLanguages(['en']);
        $andContent->setViewRule(AccessLevel::PUBLIC);
        $andContent->setAclRestrictions([$andGroup->identifier()]);
        $andContent->publish();

        $entityManager->persist($orContent);
        $entityManager->persist($andContent);
        $entityManager->flush();

        $client->request('GET', '/api/v1/content/items');
        self::assertResponseIsSuccessful();
        $anonymousPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertFalse($this->hasResource($anonymousPayload['data'], 'api-group-or-content'));
        self::assertFalse($this->hasResource($anonymousPayload['data'], 'api-group-and-content'));

        $orPlainKey = $this->createPlainApiKeyWithGroup('apigroupor', AccessLevel::USER, $orGroup);
        $client->request('GET', '/api/v1/content/items', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$orPlainKey,
        ]);

        self::assertResponseIsSuccessful();
        $orPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertTrue($this->hasResource($orPayload['data'], 'api-group-or-content'));
        self::assertFalse($this->hasResource($orPayload['data'], 'api-group-and-content'));

        $andPlainKey = $this->createPlainApiKeyWithGroup('apigroupand', AccessLevel::USER, $andGroup);
        $client->request('GET', '/api/v1/content/items', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$andPlainKey,
        ]);

        self::assertResponseIsSuccessful();
        $andPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertFalse($this->hasResource($andPayload['data'], 'api-group-or-content'));
        self::assertTrue($this->hasResource($andPayload['data'], 'api-group-and-content'));
    }

    public function testSchemasRequireAuthorApiKey(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/schemas');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSchemasReturnActiveSchemaMetadataForAuthors(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $plainKey = $this->createPlainApiKey('apischema', AccessLevel::AUTHOR);

        $client->request('GET', '/api/v1/schemas', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        $schema = $this->resourceById($payload['data'], 'api_article');
        self::assertSame('content_schema', $schema['type']);
        self::assertSame(1, $schema['attributes']['active_version']);
        self::assertSame('@content/api/article.html.twig', $schema['attributes']['custom_twig']);
        self::assertSame('title', $schema['attributes']['fields'][0]['identifier']);
    }

    public function testOpenApiIncludesContentAndSchemaEndpoints(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/content', $payload['paths']);
        self::assertArrayHasKey('/content/items', $payload['paths']);
        self::assertArrayHasKey('/schemas', $payload['paths']);
        self::assertSame(['frontend-content', 'frontend-content-items'], $payload['paths']['/content']['get']['tags']);
        self::assertSame(['backend-editor', 'backend-editor-schemas'], $payload['paths']['/schemas']['get']['tags']);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeExistingSchema($entityManager, 'api_article');
        $schema = new ContentSchema('6b000000-0000-7000-8000-000000000010', 'api_article', ContentSchemaSource::Custom, ['en' => 'API article']);
        $version = new ContentSchemaVersion(
            '6b000000-0000-7000-8000-000000000011',
            $schema,
            1,
            ['en' => 'API article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                ],
            ],
            customTwig: '@content/api/article.html.twig',
        );
        $version->activate();

        $entityManager->persist($schema);
        $entityManager->persist($version);
        $entityManager->flush();
    }

    private function removeExistingContentItem(EntityManagerInterface $entityManager, string $slug): void
    {
        $existing = $entityManager->getRepository(ContentItem::class)->findOneBy(['slug' => $slug]);
        if ($existing instanceof ContentItem) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }
    }

    private function removeExistingSchema(EntityManagerInterface $entityManager, string $identifier): void
    {
        $existing = $entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => $identifier]);
        if ($existing instanceof ContentSchema) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }
    }

    private function createPlainApiKey(string $prefix, int $accessLevel): string
    {
        $user = $this->createUserWithLevel($accessLevel, $prefix.'user', 'current-password');

        return $this->createPlainApiKeyForUser($prefix, $user, $accessLevel);
    }

    private function createPlainApiKeyWithGroup(string $prefix, int $accessLevel, AclGroup $group): string
    {
        $user = $this->createUserWithLevel($accessLevel, $prefix.'user', 'current-password');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $managedGroup = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $group->identifier()]);
        self::assertInstanceOf(AclGroup::class, $managedGroup);

        $user->clearGroups();
        $user->addGroup($managedGroup);
        $entityManager->flush();

        return $this->createPlainApiKeyForUser($prefix, $user, $accessLevel);
    }

    private function createPlainApiKeyForUser(string $prefix, UserAccount $user, int $accessLevel): string
    {
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '6c000000-0000-7000-8000-'.substr(md5($prefix.(string) $accessLevel), 0, 12),
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
     * @param list<array<string, mixed>> $resources
     */
    private function hasResource(array $resources, string $id): bool
    {
        foreach ($resources as $resource) {
            if (($resource['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
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
