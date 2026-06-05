<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Database\TablePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TablePrefixTest extends KernelTestCase
{
    public function testKnownTableInventoryCoversDoctrineMetadata(): void
    {
        $metadataTables = $this->doctrineMetadataTables();

        self::assertNotSame([], $metadataTables);
        self::assertSame([], array_values(array_diff($metadataTables, TablePrefix::TABLES)));
    }

    public function testDoctrineMetadataUsesConfiguredTablePrefix(): void
    {
        $previousServerPrefix = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $previousEnvPrefix = $_ENV['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = $_ENV['APP_DATABASE_PREFIX'] = 'studio_';

        try {
            $metadataTables = $this->doctrineMetadataTables();
        } finally {
            self::ensureKernelShutdown();

            if (null === $previousServerPrefix) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previousServerPrefix;
            }

            if (null === $previousEnvPrefix) {
                unset($_ENV['APP_DATABASE_PREFIX']);
            } else {
                $_ENV['APP_DATABASE_PREFIX'] = $previousEnvPrefix;
            }
        }

        self::assertNotSame([], $metadataTables);
        foreach ($metadataTables as $tableName) {
            self::assertStringStartsWith('studio_', $tableName);
        }
    }

    /**
     * @return list<string>
     */
    private function doctrineMetadataTables(): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tables = [];

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $tables[] = $metadata->getTableName();

            foreach ($metadata->associationMappings as $mapping) {
                if (!isset($mapping->joinTable)) {
                    continue;
                }

                $tables[] = $mapping->joinTable->name;
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }
}
