<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessLevel;
use App\Entity\ApiKey;
use App\Entity\ContentFieldValue;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiContentItemControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    private const KAEL_PATH = '/api/v1/content/items/projects/items/aurora-7/items/lore/items/characters/items/crew/items/kael-mercer';

    public function testContentItemDetailResolvesCanonicalPathLanguageAndVariant(): void
    {
        $client = self::createClient();
        $this->createContentTree();

        $client->request('GET', self::KAEL_PATH.'/variants/before-t17', ['language' => 'de']);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('content_item', $payload['data']['type']);
        self::assertSame('kael-mercer', $payload['data']['attributes']['slug']);
        self::assertSame('de', $payload['data']['attributes']['language']);
        self::assertSame('before-t17', $payload['data']['attributes']['variant']);
        self::assertSame('Kael Mercer vor T17', $payload['data']['attributes']['fields']['name']);
        self::assertSame(self::KAEL_PATH.'/items', $payload['data']['links']['children']);
        self::assertSame(self::KAEL_PATH.'/variants', $payload['data']['links']['variants']);
        self::assertSame(self::KAEL_PATH.'/revisions', $payload['data']['links']['revisions']);
    }

    public function testContentItemDetailUsesApiUserLanguageWhenLanguageQueryIsMissing(): void
    {
        $client = self::createClient();
        $this->createContentTree();
        $plainKey = $this->createPlainApiKey('apicontentlang', AccessLevel::USER, ApiKeyStatus::ReadOnly);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(\App\Entity\UserAccount::class)->findOneBy(['username' => 'apicontentlanguser']);
        self::assertInstanceOf(\App\Entity\UserAccount::class, $user);
        $user->updateSettings(['language' => 'de']);
        $entityManager->flush();

        $client->request('GET', self::KAEL_PATH.'/variants/before-t17', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('de', $payload['data']['attributes']['language']);
        self::assertSame('Kael Mercer vor T17', $payload['data']['attributes']['fields']['name']);
    }

    public function testContentItemDetailReturnsForbiddenForAuthenticatedAclDenials(): void
    {
        $client = self::createClient();
        $this->createContentTree();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $item = $entityManager->find(ContentItem::class, '6b100000-0000-7000-8000-000000000015');

        self::assertInstanceOf(ContentItem::class, $item);

        $item->setViewRule(AccessLevel::AUTHOR);
        $entityManager->flush();

        $client->request('GET', self::KAEL_PATH);
        self::assertResponseStatusCodeSame(401);

        $plainKey = $this->createPlainApiKey('apicontdenied', AccessLevel::USER, ApiKeyStatus::ReadOnly);
        $client->request('GET', self::KAEL_PATH, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testContentItemNavigationListsChildrenVariantsAndVersions(): void
    {
        $client = self::createClient();
        $this->createContentTree();
        $crewPath = '/api/v1/content/items/projects/items/aurora-7/items/lore/items/characters/items/crew';

        $client->request('GET', $crewPath.'/items');
        self::assertResponseIsSuccessful();
        $children = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(1, $children['meta']['count']);
        self::assertSame('kael-mercer', $children['data'][0]['attributes']['slug']);

        $client->request('GET', self::KAEL_PATH.'/variants');
        self::assertResponseIsSuccessful();
        $variants = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(['default', 'before-t17'], array_column($variants['data'], 'id'));

        $client->request('GET', self::KAEL_PATH.'/revisions');
        self::assertResponseStatusCodeSame(401);

        $authorKey = $this->createPlainApiKey('apirevauth', AccessLevel::AUTHOR, ApiKeyStatus::ReadOnly);
        $client->request('GET', self::KAEL_PATH.'/revisions', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$authorKey,
        ]);
        self::assertResponseStatusCodeSame(403);

        $publisherKey = $this->createPlainApiKey('apirevpub', AccessLevel::PUBLISHER, ApiKeyStatus::ReadOnly);
        $client->request('GET', self::KAEL_PATH.'/revisions', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$publisherKey,
        ]);
        self::assertResponseIsSuccessful();
        $versions = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(2, $versions['meta']['count']);
        self::assertSame('1', $versions['data'][0]['id']);
        self::assertSame('2', $versions['data'][1]['id']);
        self::assertTrue($versions['data'][0]['attributes']['active']);
        self::assertFalse($versions['data'][1]['attributes']['active']);
    }

    public function testContentItemVersionSelectorIsRegisteredButDeferred(): void
    {
        $client = self::createClient();
        $this->createContentTree();

        $client->request('GET', self::KAEL_PATH, ['language' => 'de', 'version' => '6']);

        self::assertResponseStatusCodeSame(501);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api.operation_not_implemented', $payload['error']['code']);
        self::assertSame('API-Operation "readContentVersion" ist registriert, aber noch nicht implementiert.', $payload['error']['message']);
        self::assertSame('readContentVersion', $payload['error']['context']['operation']);

        $publisherKey = $this->createPlainApiKey('apirevone', AccessLevel::PUBLISHER, ApiKeyStatus::ReadOnly);
        $client->request('GET', self::KAEL_PATH.'/revisions/1', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$publisherKey,
        ]);

        self::assertResponseStatusCodeSame(501);
        $revisionPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('getContentItemRevision', $revisionPayload['error']['context']['operation']);
    }

    public function testContentMutationEndpointsAreRegisteredButDeferred(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apicontentwrite', AccessLevel::AUTHOR, ApiKeyStatus::ReadWrite);

        $client->request('POST', '/api/v1/content/items/create', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['schema' => 'api_character'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(501);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('createContentItem', $payload['error']['context']['operation']);

        $client->request('POST', self::KAEL_PATH.'/validate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['schema' => 'api_character'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(501);
        $validatePayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('validateContentItemMutation', $validatePayload['error']['context']['operation']);

        $client->request('POST', self::KAEL_PATH.'/diff', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['schema' => 'api_character'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(501);
        $diffPayload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('diffContentItemMutation', $diffPayload['error']['context']['operation']);
    }

    private function createContentTree(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeExistingContentTree($entityManager);
        $this->removeExistingSchema($entityManager, 'api_character');

        $schema = new ContentSchema('6b100000-0000-7000-8000-000000000001', 'api_character', ContentSchemaSource::Custom, ['en' => 'API character']);
        $schemaVersion = new ContentSchemaVersion(
            '6b100000-0000-7000-8000-000000000002',
            $schema,
            1,
            ['en' => 'API character schema'],
            ['fields' => [
                ['identifier' => 'title', 'type' => 'text', 'required' => true],
                ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                ['identifier' => 'name', 'type' => 'text', 'required' => true],
            ]],
        );
        $schemaVersion->activate();
        $entityManager->persist($schema);
        $entityManager->persist($schemaVersion);

        $items = [
            $this->contentItem('6b100000-0000-7000-8000-000000000010', 'projects'),
            $this->contentItem('6b100000-0000-7000-8000-000000000011', 'aurora-7', '6b100000-0000-7000-8000-000000000010'),
            $this->contentItem('6b100000-0000-7000-8000-000000000012', 'lore', '6b100000-0000-7000-8000-000000000011'),
            $this->contentItem('6b100000-0000-7000-8000-000000000013', 'characters', '6b100000-0000-7000-8000-000000000012'),
            $this->contentItem('6b100000-0000-7000-8000-000000000014', 'crew', '6b100000-0000-7000-8000-000000000013'),
        ];
        foreach ($items as $offset => $item) {
            $item->activateRevision($this->revision(
                '6b100000-0000-7000-8000-00000000003'.$offset,
                $item,
                $schemaVersion,
                ucfirst($item->slug()),
            ));
        }

        $kael = $this->contentItem('6b100000-0000-7000-8000-000000000015', 'kael-mercer', '6b100000-0000-7000-8000-000000000014', ['default', 'before-t17']);
        $revision = $this->revision('6b100000-0000-7000-8000-000000000020', $kael, $schemaVersion, 'Kael Mercer');
        $revision->addFieldValue(new ContentFieldValue('6b100000-0000-7000-8000-000000000021', $revision, 'de', 'before-t17', 'name', 'Kael Mercer vor T17'));
        $kael->activateRevision($revision);
        $kael->addRevision($this->revision('6b100000-0000-7000-8000-000000000022', $kael, $schemaVersion, 'Kael Mercer Draft', 2));
        $items[] = $kael;

        foreach ($items as $item) {
            $entityManager->persist($item);
        }
        $entityManager->flush();
    }

    /**
     * @param list<string> $variants
     */
    private function contentItem(string $uid, string $slug, ?string $parentUid = null, array $variants = ['default']): ContentItem
    {
        $item = new ContentItem($uid, $slug);
        $item->moveTo($parentUid);
        $item->setAvailableLanguages(['de']);
        $item->setAvailableVariants($variants);
        $item->publish();

        return $item;
    }

    private function revision(string $uid, ContentItem $item, ContentSchemaVersion $schemaVersion, string $name, int $version = 1): ContentRevision
    {
        $revision = new ContentRevision($uid, $item, $version, $schemaVersion);
        $revision->addFieldValue(new ContentFieldValue(
            substr_replace($uid, 'f', 0, 1),
            $revision,
            'de',
            'default',
            'title',
            $name,
        ));
        $revision->addFieldValue(new ContentFieldValue(
            substr_replace($uid, 'e', 0, 1),
            $revision,
            'de',
            'default',
            'subtitle',
            $item->slug(),
        ));
        $revision->addFieldValue(new ContentFieldValue(
            substr_replace($uid, 'd', 0, 1),
            $revision,
            'de',
            'default',
            'name',
            $name,
        ));

        return $revision;
    }

    private function removeExistingContentTree(EntityManagerInterface $entityManager): void
    {
        for ($index = 15; $index >= 10; --$index) {
            $existing = $entityManager->getRepository(ContentItem::class)->find('6b100000-0000-7000-8000-0000000000'.$index);
            if ($existing instanceof ContentItem) {
                $entityManager->remove($existing);
            }
        }

        $entityManager->flush();
    }

    private function removeExistingSchema(EntityManagerInterface $entityManager, string $identifier): void
    {
        $existing = $entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => $identifier]);
        if ($existing instanceof ContentSchema) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }
    }

    private function createPlainApiKey(string $prefix, int $accessLevel, ApiKeyStatus $status): string
    {
        $user = $this->createUserWithLevel($accessLevel, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '6c100000-0000-7000-8000-'.substr(md5($prefix.(string) $accessLevel.$status->value), 0, 12),
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
     * @return array<string, mixed>
     */
    private function jsonPayload(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
