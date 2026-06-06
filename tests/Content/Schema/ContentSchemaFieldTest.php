<?php

declare(strict_types=1);

namespace App\Tests\Content\Schema;

use App\Content\Schema\ContentSchemaField;
use App\Content\Schema\ContentSchemaSource;
use App\Entity\ContentFieldValue;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use PHPUnit\Framework\TestCase;

final class ContentSchemaFieldTest extends TestCase
{
    public function testItDefinesRequiredBaseFieldIdentifiers(): void
    {
        self::assertSame(['title', 'subtitle'], ContentSchemaField::requiredBaseIdentifiers());
        self::assertTrue(ContentSchemaField::isRequiredBaseIdentifier('title'));
        self::assertTrue(ContentSchemaField::isRequiredBaseIdentifier('subtitle'));
        self::assertFalse(ContentSchemaField::isRequiredBaseIdentifier('body'));
    }

    public function testRequiredBaseFieldIdentifiersCanBeStoredAsFieldValues(): void
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'article');
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );
        $schemaVersion = new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                ],
            ],
        );
        $revision = new ContentRevision('33333333-3333-7333-8333-333333333333', $content, 1, $schemaVersion);

        foreach (ContentSchemaField::requiredBaseIdentifiers() as $index => $fieldIdentifier) {
            $fieldValue = new ContentFieldValue(
                sprintf('22222222-2222-7222-8222-%012d', $index + 1),
                $revision,
                'en',
                'default',
                $fieldIdentifier,
                'Value',
            );

            self::assertSame($fieldIdentifier, $fieldValue->fieldIdentifier());
        }
    }
}
