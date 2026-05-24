<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\PackageMacroRegistry;
use App\View\SystemPackageMetadataProvider;
use App\View\ViewContextEvent;
use App\View\ViewContextProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ViewContextProviderTest extends TestCase
{
    public function testItDispatchesViewContextForPackageExtensions(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::NAME, static function (ViewContextEvent $event): void {
            $event->set('package_demo', ['enabled' => true]);
        });

        $context = (new ViewContextProvider(
            new SystemPackageMetadataProvider(dirname(__DIR__, 2)),
            new PackageMacroRegistry(),
            $dispatcher,
        ))->context();

        self::assertSame('System', $context['system_package']['name']);
        self::assertSame('macros/core/content.html.twig', $context['macro_namespaces']['core']['content']);
        self::assertSame(['enabled' => true], $context['package_demo']);
    }
}
