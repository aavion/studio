<?php

declare(strict_types=1);

namespace App\Core\Extension\Content;

use App\Core\Extension\ExtensionOwnerName;
use App\Entity\ContentSchema;
use App\Entity\Extension;

final readonly class ExtensionContentSchemaIdentifier
{
    public static function isPortableIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= ContentSchema::MAX_IDENTIFIER_LENGTH;
    }

    public static function create(string $extensionName, string $schemaName): string
    {
        return ExtensionOwnerName::prefix($extensionName).$schemaName;
    }

    public static function ownedBy(ContentSchema $schema, Extension $extension): bool
    {
        return str_starts_with($schema->identifier(), self::create($extension->extensionName(), ''));
    }
}
