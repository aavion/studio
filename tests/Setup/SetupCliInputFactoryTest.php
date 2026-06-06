<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\DatabaseDriver;
use App\Setup\SetupCliInputFactory;
use App\Setup\SetupMessageKey;
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
            'app-secret' => 'app-secret-12',
            'dry-run' => false,
        ]);

        self::assertSame('test', $input->appEnv());
        self::assertSame('de', $input->language());
        self::assertSame('Option Studio', $input->siteTitle());
        self::assertSame(DatabaseDriver::SQLite, $input->databaseDriver());
        self::assertSame('sqlite:///%kernel.project_dir%/var/data_test.db', $input->databaseUrl());
        self::assertTrue($input->dryRun());
    }

    public function testItNormalizesDatabasePrefixFromCliOptions(): void
    {
        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            input: $this->stream(''),
            output: $this->stream(''),
            interactive: false,
        );

        $input = $factory->create([
            'env' => 'test',
            'language' => 'en',
            'site-title' => 'Prefixed Studio',
            'url' => 'https://option.example.test',
            'database-url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            'db-prefix' => 'studio',
            'admin-username' => 'owner',
            'admin-password' => 'Safe1!pass',
            'admin-email' => 'owner@example.test',
        ]);

        self::assertSame('studio_', $input->databasePrefix());
    }

    public function testItNormalizesDatabasePrefixFromEnvironmentDefaults(): void
    {
        $previous = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = 'envstudio';

        try {
            $factory = new SetupCliInputFactory(
                dirname(__DIR__, 2),
                extensionAvailability: [
                    'pdo_sqlite' => true,
                    'pdo_mysql' => true,
                    'pdo_pgsql' => true,
                ],
                input: $this->stream(''),
                output: $this->stream(''),
                interactive: false,
            );

            $input = $factory->create([
                'env' => 'test',
                'language' => 'en',
                'site-title' => 'Prefixed Studio',
                'url' => 'https://option.example.test',
                'database-url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                'admin-username' => 'owner',
                'admin-password' => 'Safe1!pass',
                'admin-email' => 'owner@example.test',
            ]);
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previous;
            }
        }

        self::assertSame('envstudio_', $input->databasePrefix());
    }

    public function testItKeepsExplicitEmptyDatabasePrefixEmpty(): void
    {
        $previous = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = 'envstudio';

        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            input: $this->stream(''),
            output: $this->stream(''),
            interactive: false,
        );

        try {
            $input = $factory->create([
                'env' => 'test',
                'language' => 'en',
                'site-title' => 'Unprefixed Studio',
                'url' => 'https://option.example.test',
                'database-url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                'db-prefix' => '',
                'admin-username' => 'owner',
                'admin-password' => 'Safe1!pass',
                'admin-email' => 'owner@example.test',
            ]);
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previous;
            }
        }

        self::assertNull($input->databasePrefix());
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
        $this->expectExceptionMessage(SetupMessageKey::SETUP_INPUT_ADMIN_USERNAME_INVALID);

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
                extensionAvailability: [
                    'pdo_sqlite' => true,
                    'pdo_mysql' => true,
                    'pdo_pgsql' => true,
                ],
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

    public function testItUsesEnvironmentNameForDefaultSqliteDatabaseUrl(): void
    {
        $previous = [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
            'DATABASE_URL' => $_SERVER['DATABASE_URL'] ?? null,
            '_ENV_APP_ENV' => $_ENV['APP_ENV'] ?? null,
            '_ENV_DATABASE_URL' => $_ENV['DATABASE_URL'] ?? null,
        ];

        $_SERVER['APP_ENV'] = 'staging';
        unset($_SERVER['DATABASE_URL']);
        $_ENV['APP_ENV'] = 'staging';
        unset($_ENV['DATABASE_URL']);

        try {
            $factory = new SetupCliInputFactory(
                dirname(__DIR__, 2),
                extensionAvailability: [
                    'pdo_sqlite' => true,
                    'pdo_mysql' => true,
                    'pdo_pgsql' => true,
                ],
                input: $this->stream(''),
                output: $this->stream(''),
                interactive: false,
            );

            $input = $factory->create([
                'language' => 'en',
                'site-title' => 'Env SQLite Studio',
                'url' => 'https://env.example.test',
                'admin-username' => 'owner',
                'admin-password' => 'Safe1!pass',
                'admin-email' => 'owner@example.test',
            ]);
        } finally {
            foreach (['APP_ENV', 'DATABASE_URL'] as $key) {
                $value = $previous[$key];
                if (null === $value) {
                    unset($_SERVER[$key]);
                    continue;
                }

                $_SERVER[$key] = $value;
            }
            foreach (['APP_ENV' => '_ENV_APP_ENV', 'DATABASE_URL' => '_ENV_DATABASE_URL'] as $key => $previousKey) {
                $value = $previous[$previousKey];
                if (null === $value) {
                    unset($_ENV[$key]);
                    continue;
                }

                $_ENV[$key] = $value;
            }
        }

        self::assertSame('staging', $input->appEnv());
        self::assertSame('sqlite:///%kernel.project_dir%/var/data_staging.db', $input->databaseUrl());
    }

    public function testItRejectsExplicitDatabaseUrlDriverMismatches(): void
    {
        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            extensionAvailability: [
                'pdo_sqlite' => true,
                'pdo_mysql' => true,
                'pdo_pgsql' => true,
            ],
            input: $this->stream(''),
            output: $this->stream(''),
            interactive: false,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match selected database driver "mysql"');

        $factory->create([
            'env' => 'test',
            'language' => 'en',
            'site-title' => 'Mismatched DB Studio',
            'url' => 'https://option.example.test',
            'db-driver' => 'mysql',
            'database-url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            'admin-username' => 'owner',
            'admin-password' => 'Safe1!pass',
            'admin-email' => 'owner@example.test',
        ]);
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
            'app-secret-12',
            '',
        ]));
        $outputStream = $this->stream('');
        $factory = new SetupCliInputFactory(
            dirname(__DIR__, 2),
            extensionAvailability: [
                'pdo_sqlite' => true,
                'pdo_mysql' => true,
                'pdo_pgsql' => true,
            ],
            input: $inputStream,
            output: $outputStream,
            interactive: true,
        );

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
        self::assertSame('app-secret-12', $input->appSecret());
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
