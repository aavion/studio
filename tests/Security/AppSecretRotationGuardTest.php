<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Security\AppSecretRotationGuard;
use App\Setup\SetupInputValidator;
use App\View\Alert\MercureAvailability;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AppSecretRotationGuardTest extends TestCase
{
    public function testItRejectsUnsupportedShortAppSecretBeforeRecovery(): void
    {
        $guard = $this->guardWithSecret('short');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'The configured APP_SECRET is unsupported: it must be at least %d bytes.',
            SetupInputValidator::MIN_APP_SECRET_LENGTH,
        ));

        $guard->handle();
    }

    public function testItMarksMercureUnavailableWhenRotationCannotStopHub(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE config_entry (config_key VARCHAR(190) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at VARCHAR(32) DEFAULT NULL, modified_by VARCHAR(255) DEFAULT NULL)',
        );
        $config = new Config($connection);
        self::assertTrue($config->set(MercureAvailability::AVAILABLE_KEY, true, ConfigValueType::Boolean));

        $reflection = new ReflectionClass(AppSecretRotationGuard::class);
        $guard = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($guard, $config);
        $reflection->getMethod('markMercureUnavailableAfterFailedStop')->invoke($guard);

        self::assertFalse($config->get(MercureAvailability::AVAILABLE_KEY, true));
    }

    private function guardWithSecret(string $secret): AppSecretRotationGuard
    {
        $reflection = new ReflectionClass(AppSecretRotationGuard::class);
        $guard = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('secret')->setValue($guard, $secret);

        return $guard;
    }
}
