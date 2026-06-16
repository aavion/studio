<?php

declare(strict_types=1);

namespace App\Core\Log;

use Doctrine\DBAL\Connection;

final readonly class DatabaseLogBrowser
{
    private const APPLICATION_SOURCE = ['application' => ['label' => 'admin.logs.sources.application']];
    private const SOURCES = [
        'message' => ['label' => 'admin.logs.sources.message', 'table' => 'message_log_entry'],
        'audit' => ['label' => 'admin.logs.sources.audit', 'table' => 'audit_log_entry'],
        'access' => ['label' => 'admin.logs.sources.access', 'table' => 'access_log_entry'],
        'security_signal' => ['label' => 'admin.logs.sources.security_signal', 'table' => 'security_signal_event'],
    ];

    public function __construct(
        private Connection $connection,
        private ?LogFileBrowser $fileBrowser = null,
        private LogEntryFilter $entryFilter = new LogEntryFilter(),
        private LogPagination $pagination = new LogPagination(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function browse(array $query): array
    {
        $source = $this->source($query['source'] ?? null);
        if ('application' === $source) {
            return $this->browseApplication($query);
        }

        $filters = $this->entryFilter->filters($query);
        if (!$this->supportsLevelFilter($source)) {
            $filters['level'] = '';
            $filters['levels'] = [];
        }
        $criteria = $this->criteria($source, $filters);
        $matched = $this->count($source, $criteria);
        $entries = $this->entries($source, $criteria, $filters);

        return [
            'sources' => $this->sourceOptions(),
            'selected_source' => $source,
            'capabilities' => $this->capabilities($source),
            'filters' => $filters,
            'entries' => $entries,
            'files' => [],
            'pagination' => $this->pagination->pagination($filters, $matched),
            'per_page_options' => $this->pagination->perPageOptions(),
            'time_window_options' => $this->pagination->timeWindowOptions(),
            'match_options' => $this->pagination->matchOptions(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $source, string $id): ?array
    {
        $source = $this->source($source);
        if ('application' === $source) {
            return $this->fileBrowser?->entry($source, $id);
        }

        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE uid = ?',
            self::SOURCES[$source]['table'],
        ), [$id]);

        return is_array($row) ? $this->present($source, $row) : null;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sourceOptions(): array
    {
        $options = [];

        foreach ([...self::APPLICATION_SOURCE, ...self::SOURCES] as $key => $source) {
            $options[] = ['key' => $key, 'label' => $source['label']];
        }

        return $options;
    }

    private function source(mixed $source): string
    {
        if ('application' === $source) {
            return 'application';
        }

        return is_string($source) && isset(self::SOURCES[$source]) ? $source : 'message';
    }

    /**
     * @return array{level_filter: bool, audit_action_filter: bool, signal_reason_filter: bool}
     */
    private function capabilities(string $source): array
    {
        return [
            'level_filter' => $this->supportsLevelFilter($source),
            'audit_action_filter' => 'audit' === $source,
            'signal_reason_filter' => 'security_signal' === $source,
        ];
    }

    private function supportsLevelFilter(string $source): bool
    {
        return in_array($source, ['application', 'message', 'security_signal'], true);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function browseApplication(array $query): array
    {
        if (null === $this->fileBrowser) {
            $filters = $this->entryFilter->filters($query);

            return [
                'sources' => $this->sourceOptions(),
                'selected_source' => 'application',
                'capabilities' => $this->capabilities('application'),
                'filters' => $filters,
                'entries' => [],
                'files' => [],
                'pagination' => $this->pagination->pagination($filters, 0),
                'per_page_options' => $this->pagination->perPageOptions(),
                'time_window_options' => $this->pagination->timeWindowOptions(),
                'match_options' => $this->pagination->matchOptions(),
            ];
        }

        $view = $this->fileBrowser->browse([...$query, 'source' => 'application']);
        $view['sources'] = $this->sourceOptions();
        $view['capabilities'] = $this->capabilities('application');

        return $view;
    }

    /**
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int} $filters
     *
     * @return array{where: list<string>, params: list<mixed>}
     */
    private function criteria(string $source, array $filters): array
    {
        $where = ['occurred_at >= ?'];
        $params = [$this->cutoff($filters['time_window'])];

        if ($this->supportsLevelFilter($source) && [] !== $filters['levels']) {
            $levelColumn = 'security_signal' === $source ? 'severity' : 'level';
            $where[] = $levelColumn.' IN ('.implode(', ', array_fill(0, count($filters['levels']), '?')).')';
            array_push($params, ...$filters['levels']);
        }

        if ('audit' === $source && '' !== $filters['audit_action']) {
            $where[] = 'action = ?';
            $params[] = $filters['audit_action'];
        }

        if ('security_signal' === $source && '' !== $filters['audit_action']) {
            $where[] = 'reason_code = ?';
            $params[] = $filters['audit_action'];
        }

        if ('' !== $filters['search']) {
            $columns = match ($source) {
                'access' => ['context', 'request_id', 'correlation_id', 'path', 'requested_path', 'route', 'resolved_route', 'client_ip', 'proxy_client_ip', 'visitor_id', 'host', 'user_agent', 'referrer_host'],
                'audit' => ['context', 'action', 'user_name', 'user_uid', 'request_id', 'visitor_id', 'requested_path', 'resolved_route'],
                'security_signal' => ['context', 'signal_type', 'reason_code', 'subject_type', 'subject_identifier', 'request_id', 'visitor_id', 'path', 'route'],
                default => ['context', 'message', 'code'],
            };
            $operator = 'equals' === $filters['match'] ? '= ?' : 'LIKE ?';
            $needle = 'equals' === $filters['match'] ? $filters['search'] : '%'.$filters['search'].'%';
            $where[] = '('.implode(' OR ', array_map(static fn (string $column): string => $column.' '.$operator, $columns)).')';

            foreach ($columns as $_) {
                $params[] = $needle;
            }
        }

        return ['where' => $where, 'params' => $params];
    }

    /**
     * @param array{where: list<string>, params: list<mixed>} $criteria
     */
    private function count(string $source, array $criteria): int
    {
        $value = $this->connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            self::SOURCES[$source]['table'],
            implode(' AND ', $criteria['where']),
        ), $criteria['params']);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array{where: list<string>, params: list<mixed>} $criteria
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int} $filters
     *
     * @return list<array<string, mixed>>
     */
    private function entries(string $source, array $criteria, array $filters): array
    {
        $limit = 'all' === $filters['per_page'] ? 500 : (int) $filters['per_page'];
        $offset = 'all' === $filters['per_page'] ? 0 : ($filters['page'] - 1) * (int) $filters['per_page'];
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY occurred_at DESC, uid DESC LIMIT %d OFFSET %d',
            self::SOURCES[$source]['table'],
            implode(' AND ', $criteria['where']),
            $limit,
            $offset,
        );

        return array_map(
            fn (array $row): array => $this->present($source, $row),
            $this->connection->fetchAllAssociative($sql, $criteria['params']),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(string $source, array $row): array
    {
        $context = $this->decode($row['context'] ?? '{}');
        $context = $this->displayContext($source, $row, $context);
        $message = $this->message($source, $row);
        $entry = [
            'id' => (string) $row['uid'],
            'timestamp' => (string) ($row['occurred_at'] ?? ''),
            'channel' => $source,
            'level' => (string) ($row['level'] ?? ('security_signal' === $source ? $row['severity'] ?? 'INFO' : '')),
            'message' => $message,
            'context' => $context,
            'context_json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            'raw_context' => (string) ($row['context'] ?? ''),
            'file' => '',
            'raw' => '',
            'source' => $source,
            'summary' => $this->summary($source, $row),
        ];

        return $entry;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function displayContext(string $source, array $row, array $context): array
    {
        return match ($source) {
            'audit' => [
                ...$context,
                'user' => $row['user_name'] ?? 'anonymous',
                'user_uid' => $row['user_uid'] ?? null,
                'user_access_level' => $row['user_access_level'] ?? 0,
                'action' => $row['action'] ?? 'n/a',
                'request_id' => $row['request_id'] ?? 'n/a',
                'visitor_id' => $row['visitor_id'] ?? 'n/a',
                'requested_path' => $row['requested_path'] ?? 'n/a',
                'resolved_route' => $row['resolved_route'] ?? 'n/a',
            ],
            'security_signal' => [
                ...$context,
                'signal_type' => $row['signal_type'] ?? 'n/a',
                'reason_code' => $row['reason_code'] ?? 'n/a',
                'severity' => $row['severity'] ?? 'INFO',
                'confidence' => $row['confidence'] ?? 0,
                'subject_type' => $row['subject_type'] ?? 'n/a',
                'subject_identifier' => $row['subject_identifier'] ?? 'n/a',
                'ip_derived' => (bool) ($row['ip_derived'] ?? false),
                'request_family' => $row['request_family'] ?? 'n/a',
                'request_intent' => $row['request_intent'] ?? 'n/a',
                'request_id' => $row['request_id'] ?? 'n/a',
                'visitor_id' => $row['visitor_id'] ?? 'n/a',
                'path' => $row['path'] ?? 'n/a',
                'route' => $row['route'] ?? 'n/a',
                'http_status' => $row['http_status'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
            ],
            default => $context,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function message(string $source, array $row): string
    {
        return match ($source) {
            'access' => 'access.request',
            'audit' => (string) ($row['action'] ?? 'n/a'),
            'security_signal' => (string) ($row['reason_code'] ?? $row['signal_type'] ?? 'n/a'),
            default => (string) ($row['message'] ?? 'n/a'),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function summary(string $source, array $row): string
    {
        return match ($source) {
            'access' => trim(($row['method'] ?? 'n/a').' '.($row['requested_path'] ?? $row['path'] ?? 'n/a')),
            'audit' => (string) ($row['action'] ?? 'n/a'),
            'security_signal' => trim(($row['signal_type'] ?? 'n/a').': '.($row['reason_code'] ?? 'n/a')),
            default => (string) ($row['message'] ?? 'n/a'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $encoded): array
    {
        if (!is_string($encoded) || '' === $encoded) {
            return [];
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cutoff(string $window): string
    {
        $modifier = match ($window) {
            '1h' => '-1 hour',
            '7d' => '-7 days',
            '30d' => '-30 days',
            default => '-24 hours',
        };

        return (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s');
    }
}
