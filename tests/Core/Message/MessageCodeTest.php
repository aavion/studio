<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Backend\BackendMessageCode;
use App\Api\ApiMessageCode;
use App\Content\ContentMessageCode;
use App\Core\Access\AccessMessageCode;
use App\Core\Asset\AssetMessageCode;
use App\Core\Config\ConfigMessageCode;
use App\Core\Event\EventMessageCode;
use App\Core\Lint\LintMessageCode;
use App\Core\Manifest\ManifestMessageCode;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\MessageCode;
use App\Core\Messenger\MessengerMessageCode;
use App\Core\Operation\Filesystem\FilesystemMessageCode;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\Process\ProcessMessageCode;
use App\Core\Package\PackageMessageCode;
use App\Core\Routing\RoutingMessageCode;
use App\Core\Security\SystemSecurityMessageCode;
use App\Core\Translation\TranslationMessageCode;
use App\Scheduler\SchedulerMessageCode;
use App\Security\SecurityMessageCode;
use App\Setup\SetupMessageCode;
use App\View\ViewMessageCode;
use PHPUnit\Framework\TestCase;

final class MessageCodeTest extends TestCase
{
    public function testItDefinesUniqueCodes(): void
    {
        $values = array_values(MessageCode::all());

        self::assertNotEmpty($values);
        self::assertSame($values, array_unique($values));

        foreach ($values as $value) {
            self::assertIsString($value);
            self::assertNotSame('', trim($value));
        }
    }

    public function testItKeepsCodesBoundToTheirCatalogueScope(): void
    {
        foreach (MessageCode::catalogues() as $class) {
            foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
                self::assertCatalogueNameMatchesScope($class, $name);
                self::assertCatalogueValueMatchesScope($class, $value);
            }
        }
    }

    private static function assertCatalogueNameMatchesScope(string $class, string $name): void
    {
        foreach (self::namePrefixes()[$class] ?? [] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(sprintf('%s::%s is not bound to its catalogue scope.', $class, $name));
    }

    private static function assertCatalogueValueMatchesScope(string $class, string $value): void
    {
        foreach (self::valuePrefixes()[$class] ?? [] as $prefix) {
            if (str_starts_with($value, $prefix) || $value === $prefix) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(sprintf('%s contains out-of-scope code "%s".', $class, $value));
    }

    /**
     * @return array<class-string, list<string>>
     */
    private static function namePrefixes(): array
    {
        return [
            BackendMessageCode::class => ['BACKEND_'],
            ContentMessageCode::class => ['CONTENT_'],
            AccessMessageCode::class => ['ACCESS_'],
            ApiMessageCode::class => ['API_'],
            AssetMessageCode::class => ['TAILWIND_'],
            ConfigMessageCode::class => ['CONFIG_'],
            EventMessageCode::class => ['EVENT_HOOK_'],
            LintMessageCode::class => ['LINT_'],
            ManifestMessageCode::class => ['MANIFEST_'],
            CommonMessageCode::class => ['SUCCESS', 'E_'],
            MessengerMessageCode::class => ['MESSENGER_'],
            FilesystemMessageCode::class => ['FILESYSTEM_'],
            OperationMessageCode::class => ['OPERATION_'],
            ProcessMessageCode::class => ['PROCESS_'],
            PackageMessageCode::class => ['PACKAGE_'],
            RoutingMessageCode::class => ['ABSOLUTE_URI_'],
            SystemSecurityMessageCode::class => ['SYSTEM_'],
            TranslationMessageCode::class => ['TRANSLATION_'],
            SchedulerMessageCode::class => ['SCHEDULER_'],
            SecurityMessageCode::class => ['ACL_', 'USER_', 'ACCOUNT_', 'API_KEY_', 'RATE_LIMIT_'],
            SetupMessageCode::class => ['SETUP_'],
            ViewMessageCode::class => ['VIEW_'],
        ];
    }

    /**
     * @return array<class-string, list<string>>
     */
    private static function valuePrefixes(): array
    {
        return [
            BackendMessageCode::class => ['backend.'],
            ContentMessageCode::class => ['content.'],
            AccessMessageCode::class => ['access.'],
            ApiMessageCode::class => ['api.'],
            AssetMessageCode::class => ['tailwind.'],
            ConfigMessageCode::class => ['config.'],
            EventMessageCode::class => ['event.'],
            LintMessageCode::class => ['lint.'],
            ManifestMessageCode::class => ['manifest.'],
            CommonMessageCode::class => ['SUCCESS', 'E_'],
            MessengerMessageCode::class => ['messenger.'],
            FilesystemMessageCode::class => ['filesystem.'],
            OperationMessageCode::class => ['operation.'],
            ProcessMessageCode::class => ['process.'],
            PackageMessageCode::class => ['package.'],
            RoutingMessageCode::class => ['routing.'],
            SystemSecurityMessageCode::class => ['system.'],
            TranslationMessageCode::class => ['translation.'],
            SchedulerMessageCode::class => ['scheduler.'],
            SecurityMessageCode::class => ['acl.', 'user.', 'account.', 'api_key.', 'rate_limit.'],
            SetupMessageCode::class => ['setup.'],
            ViewMessageCode::class => ['view.'],
        ];
    }
}
