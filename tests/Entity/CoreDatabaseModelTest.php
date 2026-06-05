<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigValueType;
use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Entity\AclGroup;
use App\Entity\AccountToken;
use App\Entity\ApiKey;
use App\Entity\ConfigEntry;
use App\Entity\ExtensionPackage;
use App\Entity\PackageSettingEntry;
use App\Entity\SiteMenu;
use App\Entity\SiteMenuItem;
use App\Entity\StateMarker;
use App\Entity\UserAccount;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\ApiKeyStatus;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoreDatabaseModelTest extends TestCase
{
    public function testItModelsUsersGroupsAndApiKeys(): void
    {
        $contentAuthors = new AclGroup(
            '11111111-1111-7111-8111-111111111111',
            'content_authors',
            ['en' => 'Content authors'],
            AccessLevel::AUTHOR,
        );
        $reviewBoard = new AclGroup(
            '22222222-2222-7222-8222-222222222222',
            'review_board',
            ['en' => 'Review board'],
            AccessLevel::MANAGER,
        );
        $user = new UserAccount(
            '33333333-3333-7333-8333-333333333333',
            'dominique',
            'Dom@Example.COM',
            'hash',
            ['display_name' => 'Dominique'],
            role: UserRole::Manager,
        );
        $user->addGroup($contentAuthors);
        $user->addGroup($reviewBoard);
        $hmacHash = hash_hmac('sha256', 'plain-key', 'app-secret');
        $apiKey = new ApiKey(
            '44444444-4444-7444-8444-444444444444',
            'abcd1234',
            $hmacHash,
            'v1.test.encrypted-key',
            $user,
            ApiKeyStatus::ReadWrite,
        );
        $accountToken = new AccountToken(
            '55555555-5555-7555-8555-555555555555',
            hash('sha256', 'plain-account-token'),
            AccountTokenType::Invitation,
            'Invitee@Example.COM',
            ['launch_team'],
        );

        self::assertSame(AccessLevel::MANAGER, $user->accessLevel());
        self::assertSame('dominique', $user->getUserIdentifier());
        self::assertSame('dom@example.com', $user->email());
        self::assertSame('hash', $user->getPassword());
        self::assertSame(['ROLE_PUBLIC', 'ROLE_USER', 'ROLE_MODERATOR', 'ROLE_AUTHOR', 'ROLE_PUBLISHER', 'ROLE_CURATOR', 'ROLE_MANAGER'], $user->getRoles());
        self::assertSame(UserAccountStatus::Active, $user->status());
        self::assertSame(['language' => 'default'], $user->settings());
        self::assertSame(AccessLevel::AUTHOR, $contentAuthors->minRole());
        self::assertSame('abcd1234', $apiKey->prefix());
        self::assertSame($hmacHash, $apiKey->hmacHash());
        self::assertSame('v1.test.encrypted-key', $apiKey->encryptedKey());
        self::assertSame(ApiKeyStatus::ReadWrite, $apiKey->status());
        self::assertSame(AccountTokenType::Invitation, $accountToken->type());
        self::assertSame('invitee@example.com', $accountToken->email());
        self::assertSame(AccountTokenStatus::Pending, $accountToken->status());
        self::assertSame(UserRole::User, $accountToken->role());
        self::assertSame(['launch_team'], $accountToken->groupIdentifiers());
        self::assertTrue($accountToken->status()->isUsable());
        self::assertSame(MessageKey::API_KEY_STATUS_READ_WRITE, ApiKeyStatus::ReadWrite->messageKey());
        self::assertSame(MessageKey::API_KEY_STATUS_READ_ONLY, ApiKeyStatus::ReadOnly->messageKey());
        self::assertSame(MessageKey::API_KEY_STATUS_REVOKED, ApiKeyStatus::Revoked->messageKey());
        self::assertTrue(ApiKeyStatus::ReadWrite->isActive());
        self::assertTrue(ApiKeyStatus::ReadWrite->allowsWrite());
        self::assertTrue(ApiKeyStatus::ReadOnly->isActive());
        self::assertFalse(ApiKeyStatus::ReadOnly->allowsWrite());
        self::assertFalse(ApiKeyStatus::Revoked->isActive());
        self::assertFalse(ApiKeyStatus::Revoked->allowsWrite());

        $apiKey->revoke();

        self::assertSame(ApiKeyStatus::Revoked, $apiKey->status());

        $user->changePassword('new-hash');
        $user->changeStatus(UserAccountStatus::Inactive);

        self::assertSame('new-hash', $user->passwordHash());
        self::assertSame(UserAccountStatus::Inactive, $user->status());
    }

    public function testItRejectsShortAclGroupIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);

        new AclGroup(
            '11111111-1111-7111-8111-111111111111',
            'ab',
            ['en' => 'Short'],
            AccessLevel::USER,
        );
    }

    public function testItEnforcesUsernamePolicy(): void
    {
        $user = new UserAccount(
            '33333333-3333-7333-8333-333333333334',
            'alpha_123',
            'alpha@example.com',
            'hash',
        );

        $user->changeUsername('Alpha-123');

        self::assertSame('Alpha-123', $user->username());

        foreach (['abcd', '1abcd', 'alpha.name', 'alpha name', 'alpha@name', str_repeat('a', 31)] as $username) {
            try {
                $user->changeUsername($username);
                self::fail(sprintf('Username "%s" should have been rejected.', $username));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString(MessageKey::USERNAME_INVALID, $exception->getMessage());
            }
        }
    }

    public function testItModelsReusableStateMarkers(): void
    {
        $markedAt = new DateTimeImmutable('2026-05-24 12:00:00');
        $marker = new StateMarker(
            '99999999-9999-7999-9999-999999999999',
            'user_account',
            '33333333-3333-7333-8333-333333333333',
            'last_login',
            $markedAt,
            null,
            '127.0.0.1',
            ['source' => 'test'],
        );

        self::assertSame('user_account', $marker->subjectType());
        self::assertSame('33333333-3333-7333-8333-333333333333', $marker->subjectUid());
        self::assertSame('last_login', $marker->markerKey());
        self::assertSame($markedAt, $marker->markerAt());
        self::assertNull($marker->markerBy());
        self::assertSame('127.0.0.1', $marker->markerValue());
        self::assertSame(['source' => 'test'], $marker->metadata());
    }

    public function testItModelsConfigPackagesAndMenus(): void
    {
        $config = new ConfigEntry('content.cleanup.trash_retention_days', 30, ConfigValueType::Integer);
        $packageSetting = new PackageSettingEntry('demo_package', 'theme.variant', 'green', ConfigValueType::String);
        $package = new ExtensionPackage(
            '55555555-5555-7555-8555-555555555555',
            [PackageScope::FrontendTheme, PackageScope::Module],
            'demo_package',
            'packages/demo',
            ExtensionPackageStatus::Active,
        );
        $menu = new SiteMenu('66666666-6666-7666-8666-666666666666', 'main', ['en' => 'Main']);
        $item = new SiteMenuItem(
            '77777777-7777-7777-8777-777777777777',
            $menu,
            ['en' => 'Home'],
            'content',
            '88888888-8888-7888-8888-888888888888',
            viewMinLevel: AccessLevel::PUBLIC,
            viewGroupIdentifiers: ['project_team'],
        );
        $menu->addItem($item);

        self::assertSame('content.cleanup.trash_retention_days', $config->key());
        self::assertSame(ConfigValueType::Integer, $config->valueType());
        $config->replaceValue(0.75, ConfigValueType::Float);
        self::assertSame(0.75, $config->value());
        self::assertSame(ConfigValueType::Float, $config->valueType());
        self::assertSame('demo_package', $packageSetting->packageName());
        self::assertSame('theme.variant', $packageSetting->key());
        self::assertSame('green', $packageSetting->value());
        self::assertSame(ConfigValueType::String, $packageSetting->valueType());
        self::assertSame([PackageScope::FrontendTheme, PackageScope::Module], $package->scopes());
        self::assertSame(['frontend-theme', 'module'], $package->scopeValues());
        self::assertTrue($package->hasScope(PackageScope::FrontendTheme));
        self::assertSame(ExtensionPackageStatus::Active, $package->status());
        self::assertSame('main', $menu->identifier());
        self::assertSame(AccessLevel::PUBLIC, $item->viewMinLevel());
        self::assertSame(['project_team'], $item->viewGroupIdentifiers());
    }

    public function testItRejectsInvalidMenuAccessGroupIdentifiers(): void
    {
        $menu = new SiteMenu('66666666-6666-7666-8666-666666666666', 'main', ['en' => 'Main']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);

        new SiteMenuItem(
            '77777777-7777-7777-8777-777777777777',
            $menu,
            ['en' => 'Home'],
            'content',
            '88888888-8888-7888-8888-888888888888',
            viewGroupIdentifiers: ['Project Team'],
        );
    }

    public function testItRejectsInvalidAccessLevels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::ACCESS_LEVEL_INVALID);

        new AclGroup('11111111-1111-7111-8111-111111111111', 'bad', ['en' => 'Bad'], 42);
    }
}
