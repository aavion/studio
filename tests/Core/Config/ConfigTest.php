<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testItReadsTypedJsonConfigurationValues(): void
    {
        $connection = $this->connection();
        $connection->insert('config_entry', ['config_key' => 'user.menu.enabled', 'value' => 'true', 'value_type' => 'boolean']);
        $connection->insert('config_entry', ['config_key' => 'user.menu.sort_order', 'value' => '950', 'value_type' => 'integer']);

        $config = new Config($connection);

        self::assertTrue($config->get('user.menu.enabled', false));
        self::assertSame(950, $config->get('user.menu.sort_order', 900));
    }

    public function testItFallsBackWhenConfigurationCannotBeRead(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = new Config($connection);

        self::assertFalse($config->get('user.registration.enabled', false));
        self::assertSame(900, $config->get('user.menu.sort_order', 900));
    }

    public function testItSetsConfigurationValues(): void
    {
        $connection = $this->connection();
        $config = new Config($connection);

        $config->set('user.menu.sort_order', 875, ConfigValueType::Integer, modifiedBy: 'test');
        $config->set('user.menu.sort_order', 950, modifiedBy: 'test');

        $row = $connection->fetchAssociative('SELECT value, value_type, sensitive, modified_by FROM config_entry WHERE config_key = ?', [
            'user.menu.sort_order',
        ]);

        self::assertIsArray($row);
        self::assertSame('950', $row['value']);
        self::assertSame('integer', $row['value_type']);
        self::assertSame(0, (int) $row['sensitive']);
        self::assertSame('test', $row['modified_by']);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
