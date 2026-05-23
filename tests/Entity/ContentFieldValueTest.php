<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Message\MessageKey;
use App\Entity\ContentFieldValue;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentFieldValueTest extends TestCase
{
    public function testItStoresFieldContextAndContent(): void
    {
        $content = new ContentItem('11111111-1111-1111-1111-111111111111', 'article');
        $revision = $this->revision($content);
        $fieldValue = new ContentFieldValue(
            '22222222-2222-2222-2222-222222222222',
            $revision,
            'de',
            'compact',
            'teaser_text',
            'Kurzfassung',
        );

        self::assertSame('22222222-2222-2222-2222-222222222222', $fieldValue->uid());
        self::assertSame($revision, $fieldValue->revision());
        self::assertSame($content, $fieldValue->content());
        self::assertSame(1, $fieldValue->version());
        self::assertSame('de', $fieldValue->language());
        self::assertSame('compact', $fieldValue->variant());
        self::assertSame('teaser_text', $fieldValue->fieldIdentifier());
        self::assertSame('Kurzfassung', $fieldValue->fieldContent());
    }

    public function testItRejectsInvalidFieldIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::CONTENT_FIELD_IDENTIFIER_INVALID);

        new ContentFieldValue(
            '22222222-2222-2222-2222-222222222222',
            $this->revision(new ContentItem('11111111-1111-1111-1111-111111111111', 'article')),
            'en',
            'default',
            'Invalid-Identifier',
            null,
        );
    }

    private function revision(ContentItem $content): ContentRevision
    {
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );
        $schemaVersion = new ContentSchemaVersion(
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                    ['identifier' => 'teaser_text', 'type' => 'text'],
                ],
            ],
        );

        return new ContentRevision(
            '33333333-3333-3333-3333-333333333333',
            $content,
            1,
            $schemaVersion,
        );
    }
}
