<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageKey;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentSchemaTest extends TestCase
{
    public function testItActivatesVersionAndStoresSchemaAccessRules(): void
    {
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'static_page',
            ContentSchemaSource::Preset,
            ['en' => 'Static page', 'de' => 'Statische Seite'],
            locked: true,
        );
        $version = new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Static page schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                    ['identifier' => 'body', 'type' => 'rich_text'],
                ],
            ],
            useMinLevel: AccessLevel::PUBLIC,
            useGroupIdentifiers: ['project_team'],
            editMinLevel: AccessLevel::AUTHOR,
            editGroupIdentifiers: ['editor_override'],
            manageMinLevel: AccessLevel::MANAGER,
            manageGroupIdentifiers: ['manager_override'],
        );

        $version->activate();

        self::assertSame('static_page', $schema->identifier());
        self::assertTrue($schema->locked());
        self::assertSame('bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb', $schema->activeVersionUid());
        self::assertSame($version, $schema->activeVersion());
        self::assertSame(1, $version->version());
        self::assertSame(AccessLevel::PUBLIC, $version->useMinLevel());
        self::assertSame(['project_team'], $version->useGroupIdentifiers());
        self::assertSame(AccessLevel::AUTHOR, $version->editMinLevel());
        self::assertSame(['editor_override'], $version->editGroupIdentifiers());
        self::assertSame(AccessLevel::MANAGER, $version->manageMinLevel());
        self::assertSame(['manager_override'], $version->manageGroupIdentifiers());
        self::assertNotSame('', $version->definitionHash());
    }

    public function testItRejectsSchemasMissingRequiredBaseFields(): void
    {
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::CONTENT_SCHEMA_REQUIRED_FIELD_MISSING);

        new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                ],
            ],
        );
    }

    public function testItRejectsDuplicateFieldIdentifiers(): void
    {
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::CONTENT_SCHEMA_FIELD_DUPLICATE);

        new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                ],
            ],
        );
    }

    public function testItRejectsInvalidSchemaFieldIdentifiers(): void
    {
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::CONTENT_FIELD_IDENTIFIER_INVALID);

        new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                    ['identifier' => 'Hero Text', 'type' => 'text'],
                ],
            ],
        );
    }
}
