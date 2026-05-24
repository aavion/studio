<?php

declare(strict_types=1);

namespace App\Tests\Core\Manifest;

use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestSpec;
use App\Core\Manifest\ManifestValidator;
use PHPUnit\Framework\TestCase;

final class ManifestValidatorTest extends TestCase
{
    public function testItAcceptsManifestThatMatchesSpec(): void
    {
        $manifest = new Manifest([
            'APP_VERSION' => '0.1.0',
            'APP_CHANNEL' => 'dev',
        ]);
        $spec = ManifestSpec::create()
            ->allowOnly('APP_VERSION', 'APP_CHANNEL')
            ->require('APP_VERSION');

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertTrue($result->isSuccess());
        self::assertSame($manifest, $result->value());
    }

    public function testItReportsMissingRequiredKeys(): void
    {
        $manifest = new Manifest([
            'APP_CHANNEL' => 'dev',
        ]);
        $spec = ManifestSpec::create()->require('APP_VERSION');

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.missing_required_key', $result->firstIssue()?->code());
        self::assertSame(['key' => 'APP_VERSION'], $result->firstIssue()?->context());
    }

    public function testItReportsEmptyRequiredKeys(): void
    {
        $manifest = new Manifest([
            'APP_VERSION' => ' ',
        ]);
        $spec = ManifestSpec::create()->require('APP_VERSION');

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.missing_required_key', $result->firstIssue()?->code());
    }

    public function testItReportsUnknownKeysWhenSpecIsClosed(): void
    {
        $manifest = new Manifest([
            'APP_VERSION' => '0.1.0',
            'APP_SOURCE' => 'https://example.test/releases',
        ]);
        $spec = ManifestSpec::create()
            ->allowOnly('APP_VERSION')
            ->require('APP_VERSION');

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.unknown_key', $result->firstIssue()?->code());
        self::assertSame(['key' => 'APP_SOURCE'], $result->firstIssue()?->context());
    }

    public function testItAllowsUnknownKeysWhenSpecIsOpen(): void
    {
        $manifest = new Manifest([
            'APP_VERSION' => '0.1.0',
            'APP_SOURCE' => 'https://example.test/releases',
        ]);
        $spec = ManifestSpec::create()->require('APP_VERSION');

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertTrue($result->isSuccess());
    }

    public function testItValidatesNamespacedRequiredKeys(): void
    {
        $manifest = new Manifest([
            'PACKAGE_AUTHOR' => 'Aavion',
            'PACKAGE_NAME' => 'System',
        ]);
        $spec = ManifestSpec::forNamespace('PACKAGE', ['VERSION', 'AUTHOR', 'NAME'], ['NAME', 'VERSION']);

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.missing_required_key', $result->firstIssue()?->code());
        self::assertSame(['key' => 'PACKAGE_VERSION'], $result->firstIssue()?->context());
    }

    public function testItRejectsUnknownNamespacedKeys(): void
    {
        $manifest = new Manifest([
            'PACKAGE_VERSION' => '1.0.0',
            'PACKAGE_NAME' => 'System',
            'PACKAGE_UNDECLARED' => 'value',
        ]);
        $spec = ManifestSpec::forNamespace('PACKAGE', ['VERSION', 'AUTHOR', 'NAME'], ['NAME', 'VERSION']);

        $result = (new ManifestValidator())->validate($manifest, $spec);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.unknown_key', $result->firstIssue()?->code());
        self::assertSame(['key' => 'PACKAGE_UNDECLARED'], $result->firstIssue()?->context());
    }
}
