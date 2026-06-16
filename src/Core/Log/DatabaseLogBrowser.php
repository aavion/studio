<?php

declare(strict_types=1);

namespace App\Core\Log;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final readonly class DatabaseLogBrowser
{
    private const SOURCES = [
        'message' => ['label' => 'admin.logs.sources.message', 'table' => 'message_log_entry'],
        'audit' => ['label' => 'admin.logs.sources.audit', 'table' => 'audit_log_entry'],
        'access' => ['label' => 'admin.logs.sources.access', 'table' => 'access_log_entry'],
        'security_signal' => ['label' => 'admin.logs.sources.security_signal', 'table' => 'security_signal_event'],
    ];

    private DatabaseLogRetentionPolicy $retentionPolicy;

    public function __construct(
        private Connection $connection,
        private LogEntryFilter $entryFilter = new LogEntryFilter(),
        private LogPagination $pagination = new LogPagination(),
        private ClockInterface $clock = new NativeClock(),
        ?DatabaseLogRetentionPolicy $retentionPolicy = null,
    ) {
        $this->retentionPolicy = $retentionPolicy ?? new DatabaseLogRetentionPolicy($connection);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function browse(array $query): array
    {
        $source = $this->source($query['source'] ?? null);
        $filters = $this->entryFilter->filters($query);
        if (!$this->supportsLevelFilter($source)) {
            $filters['level'] = '';
            $filters['levels'] = [];
        }
        $criteria = $this->criteria($source, $filters);
        $matched = $this->count($source, $criteria);
        $pagination = $this->pagination->pagination($filters, $matched);
        $filters['page'] = $pagination['page'];
        $entries = $this->entries($source, $criteria, $filters);

        return [
            'sources' => $this->sourceOptions(),
            'selected_source' => $source,
            'capabilities' => $this->capabilities($source),
            'filters' => $filters,
            'entries' => $entries,
            'files' => [],
            'pagination' => $pagination,
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
        $where = ['uid = ?'];
        $params = [$id];

        if ('security_signal' === $source) {
            $where[] = 'expires_at > ?';
            $params[] = $this->now();
        } elseif (in_array($source, ['message', 'audit', 'access'], true)) {
            $where[] = 'occurred_at >= ?';
            $params[] = $this->retentionCutoff($source);
        }

        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE %s',
            self::SOURCES[$source]['table'],
            implode(' AND ', $where),
        ), $params);

        return is_array($row) ? $this->present($source, $row) : null;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sourceOptions(): array
    {
        $options = [];

        foreach (self::SOURCES as $key => $source) {
            $options[] = ['key' => $key, 'label' => $source['label']];
        }

        return $options;
    }

    private function source(mixed $source): string
    {
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
        return in_array($source, ['message', 'security_signal'], true);
    }

    /**
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int} $filters
     *
     * @return array{where: list<string>, params: list<mixed>}
     */
    private function criteria(string $source, array $filters): array
    {
        $where = ['occurred_at >= ?'];
        $params = [$this->cutoff($source, $filters['time_window'])];

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

        if ('security_signal' === $source) {
            $where[] = 'expires_at > ?';
            $params[] = $this->now();
        }

        if ('' !== $filters['search']) {
            $columns = match ($source) {
                'access' => ['context', 'request_id', 'correlation_id', 'path', 'requested_path', 'route', 'resolved_route', 'client_ip', 'proxy_client_ip', 'visitor_id', 'host', 'user_agent', 'referrer_host'],
                'audit' => ['context', 'action', 'user_name', 'user_uid', 'request_id', 'visitor_id', 'requested_path', 'resolved_route'],
                'security_signal' => ['context', 'signal_type', 'reason_code', 'subject_type', 'subject_identifier', 'request_id', 'visitor_id', 'path', 'route'],
                default => ['context', 'message', 'code'],
            };
            $operator = 'equals' === $filters['match'] ? '= ?' : 'LIKE ?';
            $needle = mb_strtolower($filters['search']);
            $needle = 'equals' === $filters['match'] ? $needle : '%'.$needle.'%';
            $where[] = '('.implode(' OR ', array_map(fn (string $column): string => $this->caseInsensitiveSearchExpression($column).' '.$operator, $columns)).')';

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
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int} $filters
     *
     * @return list<array<string, mixed>>
     */
    private function entries(string $source, array $criteria, array $filters): array
    {
        $limit = $filters['per_page'];
        $offset = ($filters['page'] - 1) * $filters['per_page'];
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
            'access' => [
                ...$context,
                'request_id' => $row['request_id'] ?? 'n/a',
                'correlation_id' => $row['correlation_id'] ?? 'n/a',
                'method' => $row['method'] ?? 'n/a',
                'path' => $row['path'] ?? 'n/a',
                'requested_path' => $row['requested_path'] ?? $row['path'] ?? 'n/a',
                'route' => $row['route'] ?? 'n/a',
                'resolved_route' => $row['resolved_route'] ?? $row['route'] ?? 'n/a',
                'surface' => $row['surface'] ?? 'n/a',
                'http_status' => $row['http_status'] ?? null,
                'visitor_id' => $row['visitor_id'] ?? 'n/a',
                'client_ip' => $row['client_ip'] ?? 'n/a',
                'proxy_client_ip' => $row['proxy_client_ip'] ?? 'n/a',
                'host' => $row['host'] ?? 'n/a',
                'user_agent' => $row['user_agent'] ?? 'n/a',
                'referrer_host' => $row['referrer_host'] ?? 'n/a',
                'city' => $row['city'] ?? 'n/a',
                'state' => $row['state'] ?? 'n/a',
                'country' => $row['country'] ?? 'n/a',
                'continent' => $row['continent'] ?? 'n/a',
            ],
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

    private function cutoff(string $source, string $window): string
    {
        $modifier = match ($window) {
            '1h' => '-1 hour',
            '7d' => '-7 days',
            '30d' => '-30 days',
            default => '-24 hours',
        };
        $cutoff = $this->clock->now()->modify($modifier);

        if (in_array($source, ['message', 'audit', 'access'], true)) {
            $retentionCutoff = $this->clock->now()->modify($this->retentionModifier($source));
            if ($retentionCutoff > $cutoff) {
                $cutoff = $retentionCutoff;
            }
        }

        return $cutoff->format('Y-m-d H:i:s');
    }

    private function retentionCutoff(string $source): string
    {
        return $this->clock->now()->modify($this->retentionModifier($source))->format('Y-m-d H:i:s');
    }

    private function retentionModifier(string $source): string
    {
        return sprintf('-%d days', $this->retentionPolicy->retentionDaysForSource($source));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function searchExpression(string $column): string
    {
        if ('context' !== $column) {
            return $column;
        }

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return 'CAST(context AS CHAR)';
        }

        return 'CAST(context AS TEXT)';
    }

    private function caseInsensitiveSearchExpression(string $column): string
    {
        return 'LOWER('.$this->searchExpression($column).')';
    }
}
