<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageContributions;
use App\Core\Package\Settings\PackageSettingDefinition;
use App\Scheduler\SchedulerTaskDefinition;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use PHPUnit\Framework\TestCase;

final class PackageContributionsTest extends TestCase
{
    public function testItCollectsPackageContributionsInOrder(): void
    {
        $staticView = new StaticViewInjection(
            'pkg-demo-route',
            ViewSurface::Public,
            'demo',
            'pkg.demo.route',
            '@frontend/demo/route.html.twig',
        );
        $setting = new PackageSettingDefinition(
            'demo',
            'display.mode',
            'pkg.demo.settings.display_mode.label',
            'compact',
        );
        $schedulerTask = SchedulerTaskDefinition::command(
            'demo.cleanup',
            'pkg.demo.scheduler.cleanup.label',
            'pkg.demo.scheduler.cleanup.description',
            'demo:cleanup',
            '*/15 * * * *',
            'demo',
        );

        $contributions = PackageContributions::create()
            ->staticView($staticView)
            ->setting($setting)
            ->schedulerTask($schedulerTask);

        self::assertSame([$staticView, $setting, $schedulerTask], iterator_to_array($contributions));
    }
}
