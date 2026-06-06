<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Content\ContentMessageKey;
use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Routing\ContentSystemRoute;
use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessLevel;
use App\Core\Access\AccessMessageKey;
use App\Entity\ContentFieldValue;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentItemTest extends TestCase
{
    public function testItCreatesDraftContentWithMetadataDefaults(): void
    {
        $content = new ContentItem(
            '11111111-1111-7111-8111-111111111111',
            'hello-world',
            ['seo_robots' => 'index,follow'],
        );

        self::assertSame('11111111-1111-7111-8111-111111111111', $content->uid());
        self::assertSame('hello-world', $content->slug());
        self::assertSame(ContentStatus::Draft, $content->status());
        self::assertSame(ContentVisibility::Public, $content->visibility());
        self::assertSame(ContentSystemRoute::ROOT_PARENT_UID, $content->parentUid());
        self::assertSame([], $content->availableLanguages());
        self::assertSame(['default'], $content->availableVariants());
        self::assertSame('index,follow', $content->metadataValue('seo_robots'));
    }

    public function testItTracksPublishArchiveAndVersionState(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'release-note');

        $content->publish('editor');
        $content->bumpVersion('editor');

        self::assertSame(ContentStatus::Published, $content->status());
        self::assertSame(2, $content->version());

        $content->archive('admin');

        self::assertSame(ContentStatus::Archived, $content->status());
    }

    public function testItStoresHierarchyRoutingAndAccessMetadata(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'child');
        $schema = new ContentSchema(
            '33333333-3333-7333-8333-333333333333',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );

        $content->moveTo('22222222-2222-7222-8222-222222222222', 20);
        $content->setSchema($schema);
        $content->setAvailableLanguages(['en', 'de', 'en']);
        $content->setAvailableVariants(['default', 'compact']);
        $content->setVisibility(ContentVisibility::Private);
        $content->setAclRestrictions(['launch_team', 'project_reviewers']);

        self::assertSame('22222222-2222-7222-8222-222222222222', $content->parentUid());
        self::assertSame(20, $content->sortOrder());
        self::assertSame('33333333-3333-7333-8333-333333333333', $content->schemaUid());
        self::assertSame($schema, $content->schema());
        self::assertSame(['en', 'de'], $content->availableLanguages());
        self::assertSame(['default', 'compact'], $content->availableVariants());
        self::assertSame(ContentVisibility::Private, $content->visibility());
        self::assertSame(['launch_team', 'project_reviewers'], $content->aclRestrictions());
    }

    public function testItAllowsTheVirtualSystemParent(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'footer');

        $content->moveTo(ContentSystemRoute::VIRTUAL_PARENT_UID);

        self::assertSame(ContentSystemRoute::VIRTUAL_PARENT_UID, $content->parentUid());
    }

    public function testItNormalizesNullParentToRootParent(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'home');

        $content->moveTo(null);

        self::assertSame(ContentSystemRoute::ROOT_PARENT_UID, $content->parentUid());
    }

    public function testItActivatesRevisionAndAttachesFieldValuesThroughRevision(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'article');
        $schemaVersion = $this->schemaVersion();
        $revision = new ContentRevision(
            '33333333-3333-7333-8333-333333333333',
            $content,
            2,
            $schemaVersion,
        );
        $fieldValue = new ContentFieldValue(
            '22222222-2222-7222-8222-222222222222',
            $revision,
            'en',
            'default',
            'body',
            ['html' => '<p>Hello</p>'],
        );

        $revision->addFieldValue($fieldValue);
        $content->activateRevision($revision);

        self::assertCount(1, $content->revisions());
        self::assertSame('33333333-3333-7333-8333-333333333333', $content->activeRevisionUid());
        self::assertSame($revision, $content->activeRevision());
        self::assertSame('aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa', $content->schemaUid());
        self::assertSame(1, $content->schemaVersion());
        self::assertSame(2, $content->version());
        self::assertSame($revision, $fieldValue->revision());
        self::assertSame($content, $fieldValue->content());
        self::assertSame(['html' => '<p>Hello</p>'], $fieldValue->fieldContent());
    }

    public function testItStoresCapabilityAccessRules(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'private-project');

        $content->setViewRule(AccessLevel::AUTHOR, ['project_team']);
        $content->setEditRule(AccessLevel::MANAGER);
        $content->setManageRule(null, ['release_board']);

        self::assertSame(AccessLevel::AUTHOR, $content->viewMinLevel());
        self::assertSame(['project_team'], $content->viewGroupIdentifiers());
        self::assertSame(AccessLevel::MANAGER, $content->editMinLevel());
        self::assertNull($content->editGroupIdentifiers());
        self::assertNull($content->manageMinLevel());
        self::assertSame(['release_board'], $content->manageGroupIdentifiers());
    }

    public function testItRejectsInvalidAccessGroupIdentifiers(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'private-project');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AccessMessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);

        $content->setViewRule(AccessLevel::AUTHOR, ['Project Team']);
    }

    public function testItRejectsInvalidUids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_UID_INVALID);

        new ContentItem('not-a-uuid', 'article');
    }

    public function testItRejectsRequiredBaseSchemaFieldsInMetadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_METADATA_RESERVED_SCHEMA_FIELD);

        new ContentItem(
            '11111111-1111-7111-8111-111111111111',
            'article',
            ['title' => 'Schema value, not metadata'],
        );
    }

    public function testItRejectsRequiredBaseSchemaFieldMetadataUpdates(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'article');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_METADATA_RESERVED_SCHEMA_FIELD);

        $content->setMetadataValue('subtitle', 'Schema value, not metadata');
    }

    private function schemaVersion(): ContentSchemaVersion
    {
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );

        return new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                    ['identifier' => 'body', 'type' => 'rich_text'],
                ],
            ],
        );
    }
}
