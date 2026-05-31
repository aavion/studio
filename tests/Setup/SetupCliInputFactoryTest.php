<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\DatabaseDriver;
use App\Setup\SetupCliInputFactory;
use PHPUnit\Framework\TestCase;

final class SetupCliInputFactoryTest extends TestCase
{
    public function testItCreatesInputFromOptionsWithoutPrompting(): void
    {
        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            input: $this->stream(''),
            output: $this->stream(''),
            interactive: false,
        );

        $input = $factory->create([
            'env' => 'test',
            'language' => 'de',
            'site-title' => 'Option Studio',
            'url' => 'https://option.example.test',
            'database-url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            'admin-username' => 'owner',
            'admin-password' => 'Safe1!pass',
            'admin-email' => 'owner@example.test',
            'app-secret' => 'app-secret',
            'dry-run' => false,
        ]);

        self::assertSame('test', $input->appEnv());
        self::assertSame('de', $input->language());
        self::assertSame('Option Studio', $input->siteTitle());
        self::assertSame(DatabaseDriver::SQLite, $input->databaseDriver());
        self::assertSame('sqlite:///%kernel.project_dir%/var/data_test.db', $input->databaseUrl());
        self::assertTrue($input->dryRun());
    }

    public function testItRejectsInvalidAdminUsernameWithoutDatabase(): void
    {
        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            input: $this->stream(''),
            output: $this->stream(''),
            interactive: false,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Setup admin username must start with a letter');

        $factory->create([
            'env' => 'test',
            'language' => 'en',
            'site-title' => 'Option Studio',
            'url' => 'https://option.example.test',
            'database-url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            'admin-username' => 'admin.name',
            'admin-password' => 'Safe1!pass',
            'admin-email' => 'owner@example.test',
        ]);
    }

    public function testItUsesEnvironmentDefaultsWithoutPrompting(): void
    {
        $previous = [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
            'DEFAULT_URI' => $_SERVER['DEFAULT_URI'] ?? null,
            'DATABASE_URL' => $_SERVER['DATABASE_URL'] ?? null,
        ];

        $_SERVER['APP_ENV'] = 'prod';
        $_SERVER['DEFAULT_URI'] = 'https://env.example.test';
        $_SERVER['DATABASE_URL'] = 'mysql://env_user:env_pass@db.example.test:3307/env_db';

        try {
            $factory = new SetupCliInputFactory(
                dirname(__DIR__, 2),
                input: $this->stream(''),
                output: $this->stream(''),
                interactive: false,
            );

            $input = $factory->create(['no-interaction' => false]);
        } finally {
            foreach ($previous as $key => $value) {
                if (null === $value) {
                    unset($_SERVER[$key]);
                    continue;
                }

                $_SERVER[$key] = $value;
            }
        }

        self::assertSame('prod', $input->appEnv());
        self::assertSame('https://env.example.test', $input->defaultUri());
        self::assertSame(DatabaseDriver::MySql, $input->databaseDriver());
        self::assertSame('mysql://env_user:env_pass@db.example.test:3307/env_db', $input->databaseUrl());
        self::assertSame('', $input->adminPassword());
    }

    public function testItPromptsInteractivelyInSelectedLanguage(): void
    {
        $inputStream = $this->stream(implode("\n", [
            'de',
            'Prompt Studio',
            'https://prompt.example.test',
            'mysql',
            'db.example.test',
            '3307',
            'studio_db',
            'studio_user',
            'db-secret',
            'owner',
            'Safe1!pass',
            'Safe1!pass',
            'owner@example.test',
            'app-secret',
            '',
        ]));
        $outputStream = $this->stream('');
        $factory = new SetupCliInputFactory(dirname(__DIR__, 2), input: $inputStream, output: $outputStream, interactive: true);

        $input = $factory->create(['env' => 'test']);

        rewind($outputStream);
        $output = (string) stream_get_contents($outputStream);

        self::assertSame('de', $input->language());
        self::assertSame('Prompt Studio', $input->siteTitle());
        self::assertSame('https://prompt.example.test', $input->defaultUri());
        self::assertSame(DatabaseDriver::MySql, $input->databaseDriver());
        self::assertSame('db.example.test', $input->databaseHost());
        self::assertSame(3307, $input->databasePort());
        self::assertSame('owner@example.test', $input->adminEmail());
        self::assertSame('app-secret', $input->appSecret());
        self::assertStringContainsString('Installer language', $output);
        self::assertStringContainsString('Seitentitel', $output);
        self::assertStringContainsString('Datenbank-Treiber', $output);
        self::assertStringContainsString('Admin-Passwort bestätigen', $output);
    }

    public function testItRejectsUnavailableDatabaseDriverOptions(): void
    {
        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            extensionAvailability: [
                'pdo_sqlite' => true,
                'pdo_mysql' => false,
                'pdo_pgsql' => false,
            ],
            input: $this->stream(''),
            output: $this->stream(''),
            interactive: false,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Database driver "mysql" requires PHP extension "pdo_mysql".');

        $factory->create([
            'env' => 'test',
            'language' => 'en',
            'site-title' => 'Unavailable DB Studio',
            'url' => 'https://option.example.test',
            'db-driver' => 'mysql',
            'admin-username' => 'owner',
            'admin-password' => 'Safe1!pass',
            'admin-email' => 'owner@example.test',
        ]);
    }

    /**
     * @return resource
     */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
