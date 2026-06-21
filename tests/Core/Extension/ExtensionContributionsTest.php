<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionContributions;
use App\Core\Extension\ExtensionRuntimeContributionFactory;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;
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
        $databaseTable = ExtensionDatabaseTable::create('entry', [
            ExtensionDatabaseColumn::string('uid', 36),
        ], ['uid']);
        $contentSchema = ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
            'fields' => [
                ['identifier' => 'title', 'type' => 'string'],
                ['identifier' => 'subtitle', 'type' => 'string'],
            ],
        ]);

        $contributions = ExtensionContributions::create()
            ->runtime(static fn (): array => [])
            ->staticView($staticView)
            ->setting($setting)
            ->schedulerTask($schedulerTask)
            ->databaseTable($databaseTable)
            ->contentSchema($contentSchema);

        $items = iterator_to_array($contributions);

        self::assertInstanceOf(ExtensionRuntimeContributionFactory::class, $items[0]);
        self::assertSame([$staticView, $setting, $schedulerTask, $databaseTable, $contentSchema], array_slice($items, 1));
    }
}
