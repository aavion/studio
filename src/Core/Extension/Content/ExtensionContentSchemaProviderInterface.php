<?php

declare(strict_types=1);

namespace App\Core\Extension\Content;

interface ExtensionContentSchemaProviderInterface
{
    /**
     * @return iterable<ExtensionContentSchemaDefinition>
     */
    public function extensionContentSchemas(): iterable;
}
