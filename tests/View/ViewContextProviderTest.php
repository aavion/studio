<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Localization\TranslationLanguageCatalog;
use App\View\ExtensionMacroRegistry;
use App\View\SystemExtensionMetadataProvider;
use App\View\ViewContextEvent;
use App\View\ViewContextProvider;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ViewContextProviderTest extends TestCase
{
    public function testItDispatchesViewContextForExtensions(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::class, static function (ViewContextEvent $event): void {
            $event->set('extension_demo', ['enabled' => true]);
        });

        $context = (new ViewContextProvider(
            new SystemExtensionMetadataProvider(dirname(__DIR__, 2)),
            new ExtensionMacroRegistry(),
            $this->localization(),
            new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->context();

        self::assertSame('Studio', $context['system_extension']['name']);
        self::assertSame('de', $context['default_locale']);
        self::assertSame('@root/macros/core/content.html.twig', $context['macro_namespaces']['core']['content']);
        self::assertSame(['enabled' => true], $context['extension_demo']);
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
