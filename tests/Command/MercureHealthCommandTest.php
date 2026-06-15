<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MercureHealthCommand;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Mercure\MercureBinaryManager;
use App\Core\Mercure\MercureRuntime;
use App\Core\Process\DetachedProcessStarter;
use App\View\Alert\MercureAvailability;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class MercureHealthCommandTest extends TestCase
{
    public function testItReturnsSuccessWhenMercureIsDisabled(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        self::assertTrue($config->set(MercureAvailability::ENABLED_KEY, false, ConfigValueType::Boolean));

        $tester = new CommandTester(new MercureHealthCommand(new MercureAvailability(
            $config,
            new MercureRuntime(
                new MercureBinaryManager(sys_get_temp_dir().'/studio-mercure-disabled-command-test'),
                $this->hub(),
                'http://127.0.0.1:8000',
                sys_get_temp_dir(),
            ),
            new DetachedProcessStarter(),
            sys_get_temp_dir(),
        )));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Mercure is disabled', $tester->getDisplay());
    }

    private function hub(): HubInterface
    {
        return new class implements HubInterface {
            public function getPublicUrl(): string
            {
                return 'http://127.0.0.1:3000/.well-known/mercure';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                return 'test';
            }
        };
    }
}
