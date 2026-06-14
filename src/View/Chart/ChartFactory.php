<?php

declare(strict_types=1);

namespace App\View\Chart;

use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

final readonly class ChartFactory
{
    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * @param list<string|int|float> $labels
     * @param list<array<string, mixed>> $datasets
     * @param array<string, mixed> $options
     */
    public function line(array $labels, array $datasets, array $options = []): Chart
    {
        return $this->chart(Chart::TYPE_LINE, $labels, $datasets, $options);
    }

    /**
     * @param list<string|int|float> $labels
     * @param list<array<string, mixed>> $datasets
     * @param array<string, mixed> $options
     */
    public function bar(array $labels, array $datasets, array $options = []): Chart
    {
        return $this->chart(Chart::TYPE_BAR, $labels, $datasets, $options);
    }

    /**
     * @param list<string|int|float> $labels
     * @param list<array<string, mixed>> $datasets
     * @param array<string, mixed> $options
     */
    public function doughnut(array $labels, array $datasets, array $options = []): Chart
    {
        return $this->chart(Chart::TYPE_DOUGHNUT, $labels, $datasets, $options);
    }

    /**
     * @param list<int|float|null> $data
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function dataset(string $label, array $data, array $options = []): array
    {
        return ['label' => $label, 'data' => $data] + $options;
    }

    /**
     * @param list<string|int|float> $labels
     * @param list<array<string, mixed>> $datasets
     * @param array<string, mixed> $options
     */
    private function chart(string $type, array $labels, array $datasets, array $options): Chart
    {
        $chart = $this->chartBuilder->createChart($type);
        $chart->setData([
            'labels' => $labels,
            'datasets' => $datasets,
        ]);
        $chart->setOptions($options);

        return $chart;
    }
}
