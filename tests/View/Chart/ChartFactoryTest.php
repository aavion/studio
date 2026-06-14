<?php

declare(strict_types=1);

namespace App\Tests\View\Chart;

use App\View\Chart\ChartFactory;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder;
use Symfony\UX\Chartjs\Model\Chart;

final class ChartFactoryTest extends TestCase
{
    public function testItBuildsLineBarAndDoughnutCharts(): void
    {
        $factory = new ChartFactory(new ChartBuilder());
        $dataset = $factory->dataset('Visits', [12, 18], ['borderColor' => '#3451ff']);

        $line = $factory->line(['Today', 'Yesterday'], [$dataset], ['plugins' => ['legend' => ['display' => false]]]);
        $bar = $factory->bar(['Today'], [$factory->dataset('Errors', [2])]);
        $doughnut = $factory->doughnut(['Desktop', 'Mobile'], [$factory->dataset('Devices', [60, 40])]);

        self::assertSame(Chart::TYPE_LINE, $line->getType());
        self::assertSame(['Today', 'Yesterday'], $line->getData()['labels']);
        self::assertSame([$dataset], $line->getData()['datasets']);
        self::assertSame(['plugins' => ['legend' => ['display' => false]]], $line->getOptions());
        self::assertSame(Chart::TYPE_BAR, $bar->getType());
        self::assertSame(Chart::TYPE_DOUGHNUT, $doughnut->getType());
    }
}
