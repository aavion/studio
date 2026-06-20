<?php

declare(strict_types=1);

namespace App\Tests\Core\Validation;

use App\Core\Validation\IdentifierSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifierSpecTest extends TestCase
{
    public function testOwnerSlugsRequireLetterPrefix(): void
    {
        self::assertTrue(IdentifierSpec::isOwnerSlug('demo-module'));
        self::assertFalse(IdentifierSpec::isOwnerSlug('3d-gallery'));
    }

    public function testContentSlugsAllowDigitPrefix(): void
    {
        self::assertTrue(IdentifierSpec::isContentSlug('2026-report'));
        self::assertTrue(IdentifierSpec::isContentSlug('404'));
    }

    public function testDotPathIdentifiersRequireAtLeastTwoSnakeSegments(): void
    {
        self::assertTrue(IdentifierSpec::isDotPathIdentifier('admin.settings.site_title'));
        self::assertFalse(IdentifierSpec::isDotPathIdentifier('admin'));
        self::assertFalse(IdentifierSpec::isDotPathIdentifier('admin.settings-site'));
    }

    public function testSnakeIdentifiersUseLowercaseSegments(): void
    {
        self::assertTrue(IdentifierSpec::isSnakeIdentifier('content_schema'));
        self::assertFalse(IdentifierSpec::isSnakeIdentifier('content-schema'));
        self::assertFalse(IdentifierSpec::isSnakeIdentifier('ContentSchema'));
    }

    public function testPortableDatabaseIdentifiersUseSnakeShapeAndPortableLength(): void
    {
        self::assertTrue(IdentifierSpec::isPortableDatabaseIdentifier('content_schema'));
        self::assertTrue(IdentifierSpec::isPortableDatabaseIdentifier(str_repeat('a', IdentifierSpec::MAX_PORTABLE_DATABASE_IDENTIFIER_LENGTH)));
        self::assertFalse(IdentifierSpec::isPortableDatabaseIdentifier(str_repeat('a', IdentifierSpec::MAX_PORTABLE_DATABASE_IDENTIFIER_LENGTH + 1)));
        self::assertFalse(IdentifierSpec::isPortableDatabaseIdentifier('content-schema'));
    }

    public function testPascalIdentifiersAllowOperationIdStyleNames(): void
    {
        self::assertTrue(IdentifierSpec::isPascalIdentifier('listExtensions'));
        self::assertTrue(IdentifierSpec::isPascalIdentifier('ExtensionActivate'));
        self::assertFalse(IdentifierSpec::isPascalIdentifier('3dOperation'));
        self::assertFalse(IdentifierSpec::isPascalIdentifier('operation-id'));
    }

    public function testAclGroupIdentifiersUseTheSharedAclShape(): void
    {
        self::assertTrue(IdentifierSpec::isAclGroupIdentifier('site_admins'));
        self::assertFalse(IdentifierSpec::isAclGroupIdentifier('ab'));
        self::assertFalse(IdentifierSpec::isAclGroupIdentifier('site-admins'));
    }

    public function testHandlerKeysAllowLowercaseDotDashUnderscoreKeys(): void
    {
        self::assertTrue(IdentifierSpec::isHandlerKey('extensions.demo-pack.live.admin_action'));
        self::assertFalse(IdentifierSpec::isHandlerKey('Extensions.demo'));
        self::assertFalse(IdentifierSpec::isHandlerKey('extensions/demo'));
    }

    public function testMachineIdentifiersAllowSchedulerStyleIdentifiers(): void
    {
        self::assertTrue(IdentifierSpec::isMachineIdentifier('scheduler.cleanup:daily', minLength: 3, maxLength: 160));
        self::assertFalse(IdentifierSpec::isMachineIdentifier('ab', minLength: 3, maxLength: 160));
        self::assertFalse(IdentifierSpec::isMachineIdentifier('Scheduler.cleanup', minLength: 3, maxLength: 160));
    }

    public function testDatabasePrefixesDistinguishInputAndNormalizedTablePrefixes(): void
    {
        self::assertTrue(IdentifierSpec::isDatabasePrefix('studio'));
        self::assertTrue(IdentifierSpec::isDatabasePrefix('studio_'));
        self::assertFalse(IdentifierSpec::isDatabasePrefix('Studio'));
        self::assertTrue(IdentifierSpec::isDatabaseTablePrefix('studio_'));
        self::assertFalse(IdentifierSpec::isDatabaseTablePrefix('studio'));
    }

    public function testCanonicalUuidPatternRequiresLowercaseDashedUuid(): void
    {
        self::assertTrue(IdentifierSpec::isCanonicalUuid('00000000-0000-7000-8000-000000000001'));
        self::assertFalse(IdentifierSpec::isCanonicalUuid('00000000000070008000000000000001'));
        self::assertFalse(IdentifierSpec::isCanonicalUuid('00000000-0000-7000-8000-00000000000G'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSharedSlugShapes(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Demo'];
        yield 'underscore' => ['demo_module'];
        yield 'leading hyphen' => ['-demo'];
        yield 'trailing hyphen' => ['demo-'];
        yield 'double hyphen' => ['demo--module'];
        yield 'space' => ['demo module'];
        yield 'too long' => [str_repeat('a', IdentifierSpec::MAX_SLUG_LENGTH + 1)];
    }

    #[DataProvider('invalidSharedSlugShapes')]
    public function testSharedSlugSpecsRejectUnsafeShapes(string $slug): void
    {
        self::assertFalse(IdentifierSpec::isOwnerSlug($slug));
        self::assertFalse(IdentifierSpec::isContentSlug($slug));
    }
}
