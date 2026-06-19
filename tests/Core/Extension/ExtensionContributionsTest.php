<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionContributions;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Scheduler\SchedulerTaskDefinition;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use PHPUnit\Framework\TestCase;

final class ExtensionContributionsTest extends TestCase
{
    public function testItCollectsExtensionContributionsInOrder(): void
    {
        $staticView = new StaticViewInjection(
            'ext-demo-route',
            ViewSurface::Public,
            'demo',
            'ext.demo.route',
            '@frontend/demo/route.html.twig',
        );
        $setting = new ExtensionSettingDefinition(
            'demo',
            'display.mode',
            'ext.demo.settings.display_mode.label',
            'compact',
        );
        $schedulerTask = SchedulerTaskDefinition::command(
            'demo.cleanup',
            'ext.demo.scheduler.cleanup.label',
            'ext.demo.scheduler.cleanup.description',
            'demo:cleanup',
            '*/15 * * * *',
            'demo',
        );

        $contributions = ExtensionContributions::create()
            ->staticView($staticView)
            ->setting($setting)
            ->schedulerTask($schedulerTask);

        self::assertSame([$staticView, $setting, $schedulerTask], iterator_to_array($contributions));
    }
}
