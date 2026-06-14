<?php

declare(strict_types=1);

namespace App\View\Alert;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Throwable;

final readonly class UiAlertInbox
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $topics
     */
    public function append(array $topics, UiAlert $alert, ?int $ttlSeconds = 86400): ?int
    {
        $topics = $this->normalizeTopics($topics);
        if ([] === $topics) {
            return null;
        }

        $now = new DateTimeImmutable();
        $expiresAt = null;
        if (null !== $ttlSeconds && $ttlSeconds > 0) {
            $expiresAt = $now->add(new DateInterval('PT'.$ttlSeconds.'S'));
        }

        try {
            $inserted = 0;

            foreach ($topics as $topic) {
                $this->connection->insert('ui_alert_inbox', [
                    'topic' => $topic,
                    'payload' => $alert->toArray(),
                    'created_at' => $now,
                    'expires_at' => $expiresAt,
                ], [
                    'topic' => ParameterType::STRING,
                    'payload' => Types::JSON,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'expires_at' => Types::DATETIME_IMMUTABLE,
                ]);
                ++$inserted;
            }

            return $inserted;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $topics
     *
     * @return array{cursor: int, alerts: list<array<string, mixed>>}
     */
    public function poll(array $topics, int $cursor = 0, int $limit = self::DEFAULT_LIMIT): array
    {
        $topics = $this->normalizeTopics($topics);
        if ([] === $topics) {
            return ['cursor' => max(0, $cursor), 'alerts' => []];
        }

        try {
            $now = new DateTimeImmutable();
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, payload FROM ui_alert_inbox WHERE topic IN (?) AND id > ? AND (expires_at IS NULL OR expires_at > ?) ORDER BY id ASC LIMIT ?',
                [$topics, max(0, $cursor), $now, max(1, min(250, $limit))],
                [ArrayParameterType::STRING, ParameterType::INTEGER, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER],
            );
        } catch (Throwable) {
            return ['cursor' => max(0, $cursor), 'alerts' => []];
        }

        $alerts = [];
        $nextCursor = max(0, $cursor);

        foreach ($rows as $row) {
            $nextCursor = max($nextCursor, (int) ($row['id'] ?? 0));
            $payload = $this->decodePayload($row['payload'] ?? null);
            if ([] !== $payload) {
                $alerts[] = $payload;
            }
        }

        return ['cursor' => $nextCursor, 'alerts' => $alerts];
    }

    public function cleanupExpired(): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM ui_alert_inbox WHERE expires_at IS NOT NULL AND expires_at <= ?',
            [new DateTimeImmutable()],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param list<string> $topics
     *
     * @return list<string>
     */
    private function normalizeTopics(array $topics): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $topic): string => trim((string) $topic), $topics),
            static fn (string $topic): bool => '' !== $topic,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        try {
            $decoded = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }
}
