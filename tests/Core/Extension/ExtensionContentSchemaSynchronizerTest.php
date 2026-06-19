<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Content\ContentStatus;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
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

final class ExtensionContentSchemaSynchronizerTest extends KernelTestCase
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

    public function testItCreatesAndVersionsExtensionContentSchemas(): void
    {
        $synchronizer = new ExtensionContentSchemaSynchronizer($this->entityManager);
        $extension = $this->extension();

        $created = $synchronizer->apply($extension, [$this->schema('body')]);
        self::assertTrue($created->isSuccess());
        self::assertSame(['demo_module_article'], $created->value()['created']);

        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'demo_module_article']);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertTrue($schema->locked());
        self::assertSame(1, $schema->activeVersion()?->version());

        $versioned = $synchronizer->apply($extension, [$this->schema('summary')]);
        self::assertTrue($versioned->isSuccess());
        self::assertSame(['demo_module_article'], $versioned->value()['versioned']);

        $this->entityManager->clear();
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'demo_module_article']);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertSame(2, $schema->activeVersion()?->version());
    }

    public function testItDeletesExtensionContentSchemasOnPurge(): void
    {
        $synchronizer = new ExtensionContentSchemaSynchronizer($this->entityManager);
        $extension = $this->extension();
        self::assertTrue($synchronizer->apply($extension, [$this->schema('body')])->isSuccess());

        $result = $synchronizer->purge($extension);

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo_module_article'], $result->value()['deleted']);

        $this->entityManager->clear();
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'demo_module_article']);
        self::assertNull($schema);
    }

    public function testItArchivesContentAndRetainsReferencedSchemasOnPurge(): void
    {
        $synchronizer = new ExtensionContentSchemaSynchronizer($this->entityManager);
        $extension = $this->extension();
        self::assertTrue($synchronizer->apply($extension, [$this->schema('body')])->isSuccess());
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'demo_module_article']);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertNotNull($schema->activeVersion());
        $content = new ContentItem('c3000000-0000-7000-8000-000000000001', 'extension-content');
        $content->activateRevision(new ContentRevision('c3000000-0000-7000-8000-000000000101', $content, 1, $schema->activeVersion()));
        $content->publish();
        $this->entityManager->persist($content);
        $this->entityManager->flush();

        $result = $synchronizer->purge($extension);

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->value()['deleted']);
        self::assertSame('demo_module_article', $result->value()['retained'][0]['schema']);
        self::assertSame(1, $result->value()['archived_content']);
        self::assertSame(ContentStatus::Archived, $content->status());

        $this->entityManager->clear();
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'demo_module_article']);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertNull($schema->activeVersion());
    }

    private function schema(string $customField): ExtensionContentSchemaDefinition
    {
        return ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
            'fields' => [
                ['identifier' => 'title', 'type' => 'string'],
                ['identifier' => 'subtitle', 'type' => 'string'],
                ['identifier' => $customField, 'type' => 'text'],
            ],
        ]);
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000703',
            [ExtensionScope::ContentSchema],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
