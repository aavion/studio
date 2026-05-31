<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
final readonly class DoctrineTablePrefixListener
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $prefix = TablePrefix::fromEnvironment();

        if ('' === $prefix) {
            return;
        }

        $metadata = $args->getClassMetadata();
        $metadata->setPrimaryTable(['name' => TablePrefix::apply($metadata->getTableName(), $prefix)]);

        foreach ($metadata->associationMappings as $mapping) {
            if (!isset($mapping->joinTable)) {
                continue;
            }

            $mapping->joinTable->name = TablePrefix::apply($mapping->joinTable->name, $prefix);
        }
    }
}
