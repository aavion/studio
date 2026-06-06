<?php

declare(strict_types=1);

namespace App\Setup;

final class SetupPreflightRequirementCatalog
{
    /**
     * @return list<string>
     */
    public function databaseDriverExtensions(): array
    {
        return [
            'pdo_sqlite',
            'pdo_mysql',
            'pdo_pgsql',
        ];
    }

    /**
     * @return list<string>
     */
    public function mediaExtensions(): array
    {
        return [
            'imagick',
        ];
    }
}
