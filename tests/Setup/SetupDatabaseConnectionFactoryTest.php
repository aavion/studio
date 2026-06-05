<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupDatabaseConnectionFactory;
use PHPUnit\Framework\TestCase;

final class SetupDatabaseConnectionFactoryTest extends TestCase
{
    public function testItKeepsUnixSqlitePathsAbsolute(): void
    {
        $connection = (new SetupDatabaseConnectionFactory())->create('/project', 'sqlite:////tmp/studio.db');

        self::assertSame('/tmp/studio.db', $connection->getParams()['path'] ?? null);
    }

    public function testItKeepsWindowsSqliteDrivePathsUsable(): void
    {
        $connection = (new SetupDatabaseConnectionFactory())->create('C:/project', 'sqlite:///C:/studio/setup.db');

        self::assertSame('C:/studio/setup.db', $connection->getParams()['path'] ?? null);
    }
}
