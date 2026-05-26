<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\ConfigReader;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ConfigReaderTest extends TestCase
{
    public function testItReadsTypedJsonConfigurationValues(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL)');
        $connection->insert('config_entry', ['config_key' => 'user.menu.enabled', 'value' => 'true']);
        $connection->insert('config_entry', ['config_key' => 'user.menu.sort_order', 'value' => '950']);

        $reader = new ConfigReader($connection);

        self::assertTrue($reader->bool('user.menu.enabled', false));
        self::assertSame(950, $reader->int('user.menu.sort_order', 900));
    }

    public function testItFallsBackWhenConfigurationCannotBeRead(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $reader = new ConfigReader($connection);

        self::assertFalse($reader->bool('user.registration.enabled', false));
        self::assertSame(900, $reader->int('user.menu.sort_order', 900));
    }
}
