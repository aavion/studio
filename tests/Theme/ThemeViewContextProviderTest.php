<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Theme\SystemThemeMetadataProvider;
use App\Theme\ThemeMacroRegistry;
use App\Theme\ThemeViewContextEvent;
use App\Theme\ThemeViewContextProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ThemeViewContextProviderTest extends TestCase
{
    public function testItDispatchesThemeContextForExtensions(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ThemeViewContextEvent::NAME, static function (ThemeViewContextEvent $event): void {
            $event->set('module_demo', ['enabled' => true]);
        });

        $context = (new ThemeViewContextProvider(
            new SystemThemeMetadataProvider(dirname(__DIR__, 2)),
            new ThemeMacroRegistry(),
            $dispatcher,
        ))->context();

        self::assertSame('System', $context['system_theme']['name']);
        self::assertSame('macros/core/content.html.twig', $context['macro_namespaces']['core']['content']);
        self::assertSame(['enabled' => true], $context['module_demo']);
    }
}
