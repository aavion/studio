<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Content\ContentStatus;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaImpact;
use App\Core\Extension\Content\ExtensionContentSchemaSynchronizer;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionContentSchemaImpactTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItListsAndArchivesPublicContentUsingExtensionSchemas(): void
    {
        $extension = $this->extension();
        $schema = $this->moduleSchema($extension);
        $parent = new ContentItem('c1000000-0000-7000-8000-000000000001', 'parent');
        $published = $this->content('c1000000-0000-7000-8000-000000000002', 'published-child', $schema, ContentStatus::Published, $parent->uid());
        $draft = $this->content('c1000000-0000-7000-8000-000000000003', 'draft-child', $schema, ContentStatus::Draft, $parent->uid());

        $this->entityManager->persist($parent);
        $this->entityManager->persist($published);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $impact = new ExtensionContentSchemaImpact($this->entityManager);
        $review = $impact->impactForExtensions([$extension]);

        self::assertSame(2, $review['count']);
        self::assertSame(1, $review['public_count']);
        self::assertSame([
            '/parent/draft-child',
            '/parent/published-child',
        ], array_column($review['items'], 'path'));

        $archive = $impact->archivePublicContentForExtensions([$extension]);

        self::assertTrue($archive->isSuccess());
        self::assertCount(1, $archive->value()['archived']);
        self::assertSame(ContentStatus::Archived, $published->status());
        self::assertSame(ContentStatus::Draft, $draft->status());
    }

    public function testItDoesNotReportOverlappingExtensionSchemaPrefixesAsImpact(): void
    {
        $extension = $this->extension();
        $schema = $this->moduleSchema($extension);
        $published = $this->content('c1000000-0000-7000-8000-000000000004', 'published-module', $schema, ContentStatus::Published, '00000000-0000-7000-8000-000000000000');
        $this->entityManager->persist($published);
        $this->entityManager->flush();

        $impact = new ExtensionContentSchemaImpact($this->entityManager);
        $review = $impact->impactForExtensions([new Extension(
            'c1000000-0000-7000-8000-000000000704',
            [ExtensionScope::ContentSchema],
            'demo',
            'extensions/demo',
            ExtensionStatus::Active,
        )]);

        self::assertSame(0, $review['count']);
    }

    private function moduleSchema(Extension $extension): ContentSchema
    {
        $definition = ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
            'fields' => [
                ['identifier' => 'title', 'type' => 'string'],
                ['identifier' => 'subtitle', 'type' => 'string'],
            ],
        ]);
        $result = (new ExtensionContentSchemaSynchronizer($this->entityManager))->apply($extension, [$definition]);
        self::assertTrue($result->isSuccess());
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'ext11_demo_module_article']);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertNotNull($schema->activeVersion());

        return $schema;
    }

    private function content(string $uid, string $slug, ContentSchema $schema, ContentStatus $status, string $parentUid): ContentItem
    {
        $item = new ContentItem($uid, $slug);
        $item->moveTo($parentUid);
        self::assertNotNull($schema->activeVersion());
        $item->activateRevision(new ContentRevision(substr_replace($uid, '1', 0, 1), $item, 1, $schema->activeVersion()));

        match ($status) {
            ContentStatus::Published => $item->publish(),
            ContentStatus::Archived => $item->archive(),
            ContentStatus::Deleted => $item->markDeleted(),
            default => null,
        };

        return $item;
    }

    private function extension(): Extension
    {
        return new Extension(
            'c1000000-0000-7000-8000-000000000703',
            [ExtensionScope::ContentSchema],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
