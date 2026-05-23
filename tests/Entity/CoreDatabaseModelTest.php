<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigValueType;
use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\ExtensionPackageType;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\ConfigEntry;
use App\Entity\ExtensionPackage;
use App\Entity\SiteMenu;
use App\Entity\SiteMenuItem;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoreDatabaseModelTest extends TestCase
{
    public function testItModelsUsersGroupsAndApiKeys(): void
    {
        $editor = new AclGroup(
            '11111111-1111-1111-1111-111111111111',
            'editor',
            ['en' => 'Editor'],
            AccessLevel::EDITOR,
        );
        $manager = new AclGroup(
            '22222222-2222-2222-2222-222222222222',
            'manager',
            ['en' => 'Manager'],
            AccessLevel::MANAGER,
        );
        $user = new UserAccount(
            '33333333-3333-3333-3333-333333333333',
            'dominique',
            'dom@example.com',
            'hash',
            ['display_name' => 'Dominique'],
        );
        $user->addGroup($editor);
        $user->addGroup($manager);
        $hmacHash = hash_hmac('sha256', 'plain-key', 'app-secret');
        $apiKey = new ApiKey(
            '44444444-4444-4444-4444-444444444444',
            'abcd1234',
            $hmacHash,
            'v1.test.encrypted-key',
            $user,
            ApiKeyStatus::ReadWrite,
        );

        self::assertSame(AccessLevel::MANAGER, $user->maxAccessLevel());
        self::assertSame('abcd1234', $apiKey->prefix());
        self::assertSame($hmacHash, $apiKey->hmacHash());
        self::assertSame('v1.test.encrypted-key', $apiKey->encryptedKey());
        self::assertSame(ApiKeyStatus::ReadWrite, $apiKey->status());

        $apiKey->revoke();

        self::assertSame(ApiKeyStatus::Revoked, $apiKey->status());
    }

    public function testItModelsConfigPackagesAndMenus(): void
    {
        $config = new ConfigEntry('content.cleanup.trash_retention_days', 30, ConfigValueType::Integer);
        $package = new ExtensionPackage(
            '55555555-5555-5555-5555-555555555555',
            ExtensionPackageType::Theme,
            'demo_theme',
            'themes/demo',
            ExtensionPackageStatus::Active,
        );
        $menu = new SiteMenu('66666666-6666-6666-6666-666666666666', 'main', ['en' => 'Main']);
        $item = new SiteMenuItem(
            '77777777-7777-7777-7777-777777777777',
            $menu,
            ['en' => 'Home'],
            'content',
            '88888888-8888-8888-8888-888888888888',
            viewMinLevel: AccessLevel::PUBLIC,
        );
        $menu->addItem($item);

        self::assertSame('content.cleanup.trash_retention_days', $config->key());
        self::assertSame(ConfigValueType::Integer, $config->valueType());
        $config->replaceValue(0.75, ConfigValueType::Float);
        self::assertSame(0.75, $config->value());
        self::assertSame(ConfigValueType::Float, $config->valueType());
        self::assertSame(ExtensionPackageType::Theme, $package->type());
        self::assertSame(ExtensionPackageStatus::Active, $package->status());
        self::assertSame('main', $menu->identifier());
        self::assertSame(AccessLevel::PUBLIC, $item->viewMinLevel());
    }

    public function testItRejectsInvalidMenuAccessGroupIdentifiers(): void
    {
        $menu = new SiteMenu('66666666-6666-6666-6666-666666666666', 'main', ['en' => 'Main']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);

        new SiteMenuItem(
            '77777777-7777-7777-7777-777777777777',
            $menu,
            ['en' => 'Home'],
            'content',
            '88888888-8888-8888-8888-888888888888',
            viewGroupIdentifiers: ['Project Team'],
        );
    }

    public function testItRejectsInvalidAccessLevels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::ACCESS_LEVEL_INVALID);

        new AclGroup('11111111-1111-1111-1111-111111111111', 'bad', ['en' => 'Bad'], 42);
    }
}
