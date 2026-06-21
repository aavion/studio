<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Id\UuidFactory;
use App\Database\DatabaseReadyState;
use DateInterval;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

final readonly class DatabaseLogProjector
{
    private const PLACEHOLDER = 'n/a';

    public function __construct(
        private Connection $connection,
        private DatabaseLogRetentionPolicy $retentionPolicy,
        private ?DatabaseReadyState $databaseReadyState = null,
        private UuidFactory $uuidFactory = new UuidFactory(),
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordMessage(string $level, string $message, array $context): void
    {
        $this->write('message', 'message_log_entry', [
            'uid' => $this->uuidFactory->generate(),
            'occurred_at' => $this->now(),
            'level' => $this->short($level, 16),
            'message' => $this->short($message, 255),
            'code' => $this->optionalShort($context['code'] ?? null, 160),
            'context' => $this->json($context),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordAudit(array $context): void
    {
        $nested = is_array($context['context'] ?? null) ? $context['context'] : [];

        $this->write('audit', 'audit_log_entry', [
            'uid' => $this->uuidFactory->generate(),
            'occurred_at' => $this->now(),
            'user_name' => $this->short($context['user'] ?? 'anonymous', 180),
            'user_uid' => $this->optionalShort($context['user_uid'] ?? null, 36),
            'user_access_level' => is_numeric($context['user_access_level'] ?? null) ? (int) $context['user_access_level'] : 0,
            'action' => $this->short($context['action'] ?? self::PLACEHOLDER, 160),
            'request_id' => $this->short($nested['request_id'] ?? self::PLACEHOLDER, 64),
            'visitor_id' => $this->short($nested['visitor_id'] ?? self::PLACEHOLDER, 64),
            'requested_path' => $this->short($nested['requested_path'] ?? self::PLACEHOLDER, 1024),
            'resolved_route' => $this->short($nested['resolved_route'] ?? self::PLACEHOLDER, 190),
            'context' => $this->json($nested),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordAccess(array $context): void
    {
        $this->write('access', 'access_log_entry', [
            'uid' => $this->uuidFactory->generate(),
            'occurred_at' => $this->now(),
            'request_id' => $this->short($context['request_id'] ?? self::PLACEHOLDER, 64),
            'correlation_id' => $this->short($context['correlation_id'] ?? self::PLACEHOLDER, 64),
            'method' => $this->short($context['method'] ?? self::PLACEHOLDER, 16),
            'path' => $this->short($context['path'] ?? self::PLACEHOLDER, 1024),
            'requested_path' => $this->short($context['requested_path'] ?? $context['path'] ?? self::PLACEHOLDER, 1024),
            'route' => $this->short($context['route'] ?? self::PLACEHOLDER, 190),
            'resolved_route' => $this->short($context['resolved_route'] ?? $context['route'] ?? self::PLACEHOLDER, 190),
            'surface' => $this->short($context['surface'] ?? self::PLACEHOLDER, 40),
            'query_string' => $this->short($context['query_string'] ?? '', 1024),
            'http_status' => is_numeric($context['http_status'] ?? null) ? (int) $context['http_status'] : 0,
            'duration_ms' => is_numeric($context['duration_ms'] ?? null) ? (int) $context['duration_ms'] : null,
            'visitor_id' => $this->short($context['visitor_id'] ?? self::PLACEHOLDER, 64),
            'scheme' => $this->short($context['scheme'] ?? self::PLACEHOLDER, 10),
            'host' => $this->short($context['host'] ?? self::PLACEHOLDER, 255),
            'client_ip' => $this->short($context['client_ip'] ?? $context['ip'] ?? self::PLACEHOLDER, 45),
            'proxy_client_ip' => $this->short($context['proxy_client_ip'] ?? self::PLACEHOLDER, 45),
            'user_agent' => $this->short($context['user_agent'] ?? self::PLACEHOLDER, 500),
            'referrer' => $this->short($context['referrer'] ?? self::PLACEHOLDER, 1024),
            'referrer_host' => $this->short($context['referrer_host'] ?? self::PLACEHOLDER, 255),
            'accept_language' => $this->short($context['accept_language'] ?? self::PLACEHOLDER, 255),
            'preferred_language' => $this->short($context['preferred_language'] ?? self::PLACEHOLDER, 20),
            'request_content_type' => $this->short($context['request_content_type'] ?? self::PLACEHOLDER, 120),
            'response_content_type' => $this->short($context['response_content_type'] ?? self::PLACEHOLDER, 120),
            'response_size' => is_numeric($context['response_size'] ?? null) ? (int) $context['response_size'] : null,
            'city' => $this->short($context['city'] ?? self::PLACEHOLDER, 80),
            'state' => $this->short($context['state'] ?? self::PLACEHOLDER, 80),
            'country' => $this->short($context['country'] ?? self::PLACEHOLDER, 80),
            'continent' => $this->short($context['continent'] ?? self::PLACEHOLDER, 80),
            'context' => $this->json($context),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(string $source, string $table, array $values): void
    {
        if (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady()) {
            return;
        }

        try {
            $this->connection->insert($table, $values);
            $this->purge($source, $table);
        } catch (Throwable) {
            return;
        }
    }

    private function purge(string $source, string $table): void
    {
        $cutoff = $this->clock->now()->sub(new DateInterval('P'.$this->retentionPolicy->retentionDaysForSource($source).'D'));
        $this->connection->executeStatement('DELETE FROM '.$table.' WHERE occurred_at < ?', [
            $cutoff->format('Y-m-d H:i:s'),
        ]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function short(mixed $value, int $length): string
    {
        $value = is_scalar($value) ? (string) $value : self::PLACEHOLDER;
        $value = trim($value);

        return mb_substr('' === $value ? self::PLACEHOLDER : $value, 0, $length);
    }

    private function optionalShort(mixed $value, int $length): ?string
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        return $this->short($value, $length);
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            return '{}';
        }
    }
}
