<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\View\PackageMacroRegistry;
use App\View\SystemPackageMetadataProvider;
use App\View\ViewContextEvent;
use App\View\ViewContextProvider;
use App\Tests\Support\NullWorkflowResultMessageReporter;
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
            new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->context();

        self::assertSame('Studio', $context['system_package']['name']);
        self::assertSame('@root/macros/core/content.html.twig', $context['macro_namespaces']['core']['content']);
        self::assertSame(['enabled' => true], $context['package_demo']);
    }
}
