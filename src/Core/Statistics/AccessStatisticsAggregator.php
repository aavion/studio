<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use App\Core\Log\MonologLineParser;
use SplFileObject;

final readonly class AccessStatisticsAggregator
{
    private const MAX_FILES = 30;
    private const MAX_LINES_PER_FILE = 10000;
    private const TOP_LIMIT = 10;

    public function __construct(
        private string $logDir,
        private string $environment,
        private MonologLineParser $lineParser = new MonologLineParser(),
    ) {
    }

    /**
     * @return array{
     *     generated_at: string,
     *     total_requests: int,
     *     status_families: array<string, int>,
     *     top_routes: list<array{label: string, count: int}>,
     *     top_not_found: list<array{label: string, count: int}>,
     *     top_countries: list<array{label: string, count: int}>,
     *     source_files: list<string>
     * }
     */
    public function snapshot(): array
    {
        $total = 0;
        $statusFamilies = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $routes = [];
        $notFound = [];
        $countries = [];
        $files = $this->files();

        foreach ($files as $file) {
            foreach ($this->readLines($file) as $line) {
                $entry = $this->lineParser->parse($line, $file);

                if ('studio_access' !== ($entry['channel'] ?? null)) {
                    continue;
                }

                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                ++$total;
                $status = $this->intContext($context, 'http_status');
                $family = $this->statusFamily($status);
                ++$statusFamilies[$family];

                $route = $this->routeLabel($context);
                $routes[$route] = ($routes[$route] ?? 0) + 1;

                if (404 === $status) {
                    $notFound[$route] = ($notFound[$route] ?? 0) + 1;
                }

                $country = $this->stringContext($context, 'country', 'n/a');
                $countries[$country] = ($countries[$country] ?? 0) + 1;
            }
        }

        return [
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'total_requests' => $total,
            'status_families' => $statusFamilies,
            'top_routes' => $this->top($routes),
            'top_not_found' => $this->top($notFound),
            'top_countries' => $this->top($countries),
            'source_files' => array_map('basename', $files),
        ];
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->logDir.'/'.$this->environment.'.studio-access-*.log') ?: [];
        $files = array_values(array_filter($files, 'is_file'));

        usort($files, static fn (string $left, string $right): int => [
            filemtime($right) ?: 0,
            basename($right),
        ] <=> [
            filemtime($left) ?: 0,
            basename($left),
        ]);

        return array_slice($files, 0, self::MAX_FILES);
    }

    /**
     * @return list<string>
     */
    private function readLines(string $file): array
    {
        $object = new SplFileObject($file, 'r');
        $object->seek(PHP_INT_MAX);
        $lastLine = $object->key();
        $start = max(0, $lastLine - self::MAX_LINES_PER_FILE);
        $lines = [];

        for ($lineNumber = $lastLine; $lineNumber >= $start; --$lineNumber) {
            $object->seek($lineNumber);
            $line = trim((string) $object->current());

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function intContext(array $context, string $key): int
    {
        $value = $context[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stringContext(array $context, string $key, string $fallback): string
    {
        $value = $context[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function routeLabel(array $context): string
    {
        $route = $this->stringContext($context, 'route', '');

        if ('' !== $route && 'n/a' !== $route) {
            return $route;
        }

        $method = $this->stringContext($context, 'method', 'GET');
        $path = $this->stringContext($context, 'path', '/');

        return $method.' '.$path;
    }

    private function statusFamily(int $status): string
    {
        return match (intdiv(max(0, $status), 100)) {
            2 => '2xx',
            3 => '3xx',
            4 => '4xx',
            5 => '5xx',
            default => 'other',
        };
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{label: string, count: int}>
     */
    private function top(array $counts): array
    {
        arsort($counts);
        $rows = [];

        foreach (array_slice($counts, 0, self::TOP_LIMIT, true) as $label => $count) {
            $rows[] = ['label' => $label, 'count' => $count];
        }

        return $rows;
    }
}
