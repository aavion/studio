<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Backend\BackendMessageKey;
use App\Api\ApiMessageKey;
use App\Content\ContentMessageKey;
use App\Core\Access\AccessMessageKey;
use App\Core\Asset\AssetMessageKey;
use App\Core\Config\ConfigMessageKey;
use App\Core\Event\EventMessageKey;
use App\Core\Lint\LintMessageKey;
use App\Core\Manifest\ManifestMessageKey;
use App\Core\Message\MessageKey;
use App\Core\Messenger\MessengerMessageKey;
use App\Core\Operation\Filesystem\FilesystemMessageKey;
use App\Core\Operation\OperationMessageKey;
use App\Core\Operation\Process\ProcessMessageKey;
use App\Core\Package\PackageMessageKey;
use App\Core\Routing\RoutingMessageKey;
use App\Core\Security\SystemSecurityMessageKey;
use App\Core\State\StateMessageKey;
use App\Core\Statistics\StatisticsMessageKey;
use App\Core\Translation\TranslationMessageKey;
use App\Localization\CoreTranslationBootstrapper;
use App\Navigation\NavigationMessageKey;
use App\Scheduler\SchedulerMessageKey;
use App\Security\SecurityMessageKey;
use App\Setup\SetupMessageKey;
use App\View\ViewMessageKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MessageKeyTest extends TestCase
{
    public function testItDefinesUniqueTranslationReadyKeys(): void
    {
        $constants = MessageKey::all();
        $values = array_values($constants);

        self::assertNotEmpty($values);
        self::assertSame($values, array_unique($values));

        foreach ($values as $value) {
            self::assertIsString($value);
            self::assertStringStartsWith('message.', $value);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $value);
        }
    }

    public function testItKeepsKeysBoundToTheirCatalogueScope(): void
    {
        foreach (MessageKey::catalogues() as $class) {
            foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
                self::assertCatalogueNameMatchesScope($class, $name);
                self::assertCatalogueValueMatchesScope($class, $value);
            }
        }
    }

    public function testItKeepsMessageCataloguesSynchronizedWithKnownKeys(): void
    {
        $constants = MessageKey::all();
        $knownKeys = array_values($constants);
        $root = dirname(__DIR__, 3);
        $generation = (new CoreTranslationBootstrapper())->generate($root, 'test');

        self::assertTrue($generation['success'], $generation['error'] ?? 'Runtime translation generation failed.');

        $englishKeys = array_keys(self::flatten(Yaml::parseFile($root . '/translations/runtime/test/messages.en.yaml')));
        $germanKeys = array_keys(self::flatten(Yaml::parseFile($root . '/translations/runtime/test/messages.de.yaml')));

        self::assertSame([], array_values(array_diff($knownKeys, $englishKeys)));
        self::assertSame([], array_values(array_diff($knownKeys, $germanKeys)));
        self::assertSame([], array_values(array_diff($englishKeys, $germanKeys)));
        self::assertSame([], array_values(array_diff($germanKeys, $englishKeys)));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;

            if (is_array($value)) {
                $flat += self::flatten($value, $path);
                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
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
            if (str_starts_with($value, $prefix)) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(sprintf('%s contains out-of-scope key "%s".', $class, $value));
    }

    /**
     * @return array<class-string, list<string>>
     */
    private static function namePrefixes(): array
    {
        return [
            BackendMessageKey::class => ['BACKEND_'],
            ContentMessageKey::class => ['CONTENT_'],
            AccessMessageKey::class => ['ACCESS_'],
            ApiMessageKey::class => ['API_'],
            AssetMessageKey::class => ['TAILWIND_'],
            ConfigMessageKey::class => ['CONFIG_'],
            EventMessageKey::class => ['EVENT_HOOK_'],
            LintMessageKey::class => ['LINT_'],
            ManifestMessageKey::class => ['MANIFEST_'],
            MessengerMessageKey::class => ['MESSENGER_'],
            FilesystemMessageKey::class => ['FILESYSTEM_'],
            OperationMessageKey::class => ['OPERATION_'],
            ProcessMessageKey::class => ['PROCESS_'],
            PackageMessageKey::class => ['PACKAGE_'],
            RoutingMessageKey::class => ['ABSOLUTE_URI_'],
            SystemSecurityMessageKey::class => ['SYSTEM_'],
            StateMessageKey::class => ['STATE_'],
            StatisticsMessageKey::class => ['STATISTICS_'],
            TranslationMessageKey::class => ['TRANSLATION_'],
            NavigationMessageKey::class => ['MENU_'],
            SchedulerMessageKey::class => ['SCHEDULER_'],
            SecurityMessageKey::class => ['ACL_', 'USER_', 'ACCOUNT_', 'API_KEY_', 'RATE_LIMIT_', 'AUTO_BAN_'],
            SetupMessageKey::class => ['SETUP_'],
            ViewMessageKey::class => ['VIEW_'],
        ];
    }

    /**
     * @return array<class-string, list<string>>
     */
    private static function valuePrefixes(): array
    {
        return [
            BackendMessageKey::class => ['message.backend.'],
            ContentMessageKey::class => ['message.content.'],
            AccessMessageKey::class => ['message.access.'],
            ApiMessageKey::class => ['message.api.'],
            AssetMessageKey::class => ['message.tailwind.'],
            ConfigMessageKey::class => ['message.config.'],
            EventMessageKey::class => ['message.event.'],
            LintMessageKey::class => ['message.lint.'],
            ManifestMessageKey::class => ['message.manifest.'],
            MessengerMessageKey::class => ['message.messenger.'],
            FilesystemMessageKey::class => ['message.filesystem.'],
            OperationMessageKey::class => ['message.operation.'],
            ProcessMessageKey::class => ['message.process.'],
            PackageMessageKey::class => ['message.package.'],
            RoutingMessageKey::class => ['message.routing.'],
            SystemSecurityMessageKey::class => ['message.system.'],
            StateMessageKey::class => ['message.state.'],
            StatisticsMessageKey::class => ['message.statistics.'],
            TranslationMessageKey::class => ['message.translation.'],
            NavigationMessageKey::class => ['message.menu.'],
            SchedulerMessageKey::class => ['message.scheduler.'],
            SecurityMessageKey::class => ['message.acl.', 'message.user.', 'message.account_', 'message.api_key.', 'message.rate_limit.', 'message.auto_ban.'],
            SetupMessageKey::class => ['message.setup.'],
            ViewMessageKey::class => ['message.view.'],
        ];
    }
}
