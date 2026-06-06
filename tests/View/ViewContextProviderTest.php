<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Localization\TranslationLanguageCatalog;
use App\View\PackageMacroRegistry;
use App\View\SystemPackageMetadataProvider;
use App\View\ViewContextEvent;
use App\View\ViewContextProvider;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ViewContextProviderTest extends TestCase
{
    public function testItDispatchesViewContextForPackageExtensions(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::class, static function (ViewContextEvent $event): void {
            $event->set('package_demo', ['enabled' => true]);
        });

        $context = (new ViewContextProvider(
            new SystemPackageMetadataProvider(dirname(__DIR__, 2)),
            new PackageMacroRegistry(),
            $this->localization(),
            new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->context();

        self::assertSame('Studio', $context['system_package']['name']);
        self::assertSame('de', $context['default_locale']);
        self::assertSame('@root/macros/core/content.html.twig', $context['macro_namespaces']['core']['content']);
        self::assertSame(['enabled' => true], $context['package_demo']);
    }

    private function localization(): ContentRouteLocalization
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(ContentRouteLocalization::DEFAULT_LANGUAGE_KEY, 'de', ConfigValueType::String);

        return new ContentRouteLocalization($config, new TranslationLanguageCatalog(dirname(__DIR__, 2)));
    }
}
