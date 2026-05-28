<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporterInterface;
use App\Security\UserFlowConfig;
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

    public function testUserFlowConfigNormalizesTokenLifecycleSettings(): void
    {
        $connection = $this->connection();
        $connection->insert('config_entry', ['config_key' => UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, 'value' => '36', 'value_type' => 'integer']);
        $connection->insert('config_entry', ['config_key' => UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, 'value' => '"Admin@Example.Test"', 'value_type' => 'string']);
        $connection->insert('config_entry', ['config_key' => UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, 'value' => '"Security@Example.Test"', 'value_type' => 'string']);
        $config = new UserFlowConfig(new Config($connection));

        self::assertSame(36, $config->accountLinkTtlHours());
        self::assertSame('+36 hours', $config->accountLinkTtl());
        self::assertSame('admin@example.test', $config->registrationAdminNotificationEmail());
        self::assertSame('security@example.test', $config->securityNotificationEmail());
        self::assertFalse($config->usernameChangeEnabled());

        $connection->insert('config_entry', ['config_key' => UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, 'value' => 'true', 'value_type' => 'boolean']);

        self::assertTrue($config->usernameChangeEnabled());
    }

    public function testItFallsBackWhenConfigurationCannotBeRead(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = new Config($connection);

        self::assertSame('disabled', $config->get('user.registration.mode', 'disabled'));
        self::assertSame(900, $config->get('user.menu.sort_order', 900));
    }

    public function testItSetsConfigurationValues(): void
    {
        $connection = $this->connection();
        $config = new Config($connection);

        self::assertTrue($config->set('user.menu.sort_order', 875, ConfigValueType::Integer, modifiedBy: 'test'));
        self::assertTrue($config->set('user.menu.sort_order', 950, modifiedBy: 'test'));

        $row = $connection->fetchAssociative('SELECT value, value_type, sensitive, modified_by FROM config_entry WHERE config_key = ?', [
            'user.menu.sort_order',
        ]);

        self::assertIsArray($row);
        self::assertSame('950', $row['value']);
        self::assertSame('integer', $row['value_type']);
        self::assertSame(0, (int) $row['sensitive']);
        self::assertSame('test', $row['modified_by']);
    }

    public function testItReportsInvalidConfigurationKeys(): void
    {
        $reporter = new RecordingConfigMessageReporter();
        $config = new Config($this->connection(), $reporter);

        self::assertSame('fallback', $config->get('InvalidKey', 'fallback'));
        self::assertFalse($config->set('InvalidKey', true));

        self::assertCount(2, $reporter->messages);
        self::assertSame(MessageKey::CONFIG_KEY_INVALID, $reporter->messages[0]->translationKey());
        self::assertSame(MessageKey::CONFIG_KEY_INVALID, $reporter->messages[1]->translationKey());
        self::assertSame('config.get', $reporter->messages[0]->context()['operation']);
        self::assertSame('config.set', $reporter->messages[1]->context()['operation']);
    }

    public function testItReportsStoredJsonErrors(): void
    {
        $connection = $this->connection();
        $connection->insert('config_entry', [
            'config_key' => 'user.menu.enabled',
            'value' => '{broken-json',
            'value_type' => 'boolean',
        ]);
        $reporter = new RecordingConfigMessageReporter();
        $config = new Config($connection, $reporter);

        self::assertTrue($config->get('user.menu.enabled', true));

        self::assertCount(1, $reporter->messages);
        self::assertSame(MessageCode::CONFIG_VALUE_INVALID, $reporter->messages[0]->code());
        self::assertSame(MessageKey::CONFIG_VALUE_INVALID, $reporter->messages[0]->translationKey());
        self::assertSame('user.menu.enabled', $reporter->messages[0]->context()['config_key']);
    }

    public function testItReportsReadAndWriteFailures(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $reporter = new RecordingConfigMessageReporter();
        $config = new Config($connection, $reporter);

        self::assertSame('fallback', $config->get('user.menu.enabled', 'fallback'));
        self::assertFalse($config->set('user.menu.enabled', true));

        self::assertCount(2, $reporter->messages);
        self::assertSame(MessageCode::CONFIG_READ_FAILED, $reporter->messages[0]->code());
        self::assertSame(MessageCode::CONFIG_WRITE_FAILED, $reporter->messages[1]->code());
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}

final class RecordingConfigMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<Message>
     */
    public array $messages = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->messages[] = $message;

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            $message = $record['message'];

            if ($message instanceof Message) {
                $this->messages[] = $message;
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
