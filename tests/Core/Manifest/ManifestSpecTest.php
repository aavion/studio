<?php

declare(strict_types=1);

namespace App\Tests\Core\Manifest;

use App\Core\Manifest\ManifestSpec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ManifestSpecTest extends TestCase
{
    public function testItBuildsRequiredAndAllowedKeys(): void
    {
        $spec = ManifestSpec::create()
            ->require('APP_VERSION')
            ->allow('APP_CHANNEL');

        self::assertSame(['APP_VERSION'], $spec->requiredKeys());
        self::assertSame(['APP_VERSION', 'APP_CHANNEL'], $spec->allowedKeys());
        self::assertFalse($spec->allowsUnknownKeys());
    }

    public function testItCanAllowOnlyKnownKeys(): void
    {
        $spec = ManifestSpec::create()
            ->allowOnly('APP_VERSION', 'APP_CHANNEL')
            ->require('APP_VERSION');

        self::assertSame(['APP_VERSION'], $spec->requiredKeys());
        self::assertSame(['APP_VERSION', 'APP_CHANNEL'], $spec->allowedKeys());
    }

    public function testItBuildsNamespacedSpecsFromShortKeys(): void
    {
        $spec = ManifestSpec::forNamespace('EXTENSION', ['VERSION', 'AUTHOR', 'NAME'], ['NAME', 'VERSION']);

        self::assertSame(['EXTENSION_NAME', 'EXTENSION_VERSION'], $spec->requiredKeys());
        self::assertSame(['EXTENSION_VERSION', 'EXTENSION_AUTHOR', 'EXTENSION_NAME'], $spec->allowedKeys());
        self::assertFalse($spec->allowsUnknownKeys());
    }

    public function testItAllowsUnknownKeysByDefault(): void
    {
        $spec = ManifestSpec::create()->require('APP_VERSION');

        self::assertNull($spec->allowedKeys());
        self::assertTrue($spec->allowsUnknownKeys());
    }

    public function testItRejectsInvalidSpecKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid manifest spec key "app.version".');

        ManifestSpec::create()->require('app.version');
    }

    public function testItRejectsInvalidNamespaceNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid manifest namespace "theme".');

        ManifestSpec::forNamespace('theme', ['VERSION']);
    }

    public function testItRejectsInvalidNamespacedKeyParts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid manifest key "theme.version".');

        ManifestSpec::forNamespace('EXTENSION', ['theme.version']);
    }

    public function testItRejectsRequiredKeysOutsideAllowedKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Required manifest key "APP_VERSION" is not present in allowed keys.');

        ManifestSpec::create()
            ->require('APP_VERSION')
            ->allowOnly('APP_CHANNEL');
    }
}
