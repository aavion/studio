<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

interface ExtensionDatabaseProviderInterface
{
    /**
     * @return iterable<ExtensionDatabaseTable>
     */
    public function extensionDatabaseTables(): iterable;
}
