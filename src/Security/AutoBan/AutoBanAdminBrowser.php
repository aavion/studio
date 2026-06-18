<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

final readonly class AutoBanAdminBrowser
{
    private const TABLE = 'security_signal_event';

    public function __construct(
        private AutoBanStore $store,
        private Connection $connection,
        private ?MessageReporterInterface $messageReporter = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeList(): array
    {
        return array_map(
            static fn (ActiveAutoBan $ban): array => $ban->toArray(),
            $this->store->activeBans(),
        );
    }

    /**
     * @return array{ban: array<string, mixed>, signals: list<array<string, mixed>>}|null
     */
    public function detail(string $key): ?array
    {
        $ban = $this->store->activeByKey($key);
        if (!$ban instanceof ActiveAutoBan) {
            return null;
        }

        return [
            'ban' => $ban->toArray(),
            'signals' => $this->signals($ban),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signals(ActiveAutoBan $ban): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT uid, occurred_at, signal_type, reason_code, severity, confidence, request_id, visitor_id, path, route, http_status, context FROM '.self::TABLE.' WHERE subject_type = ? AND subject_identifier = ? AND expires_at > ? ORDER BY occurred_at DESC LIMIT 100',
                [
                    $ban->subjectType(),
                    $ban->subjectIdentifier(),
                    $this->clock->now()->format('Y-m-d H:i:s'),
                ],
            );

            return array_map([$this, 'presentSignal'], $rows);
        } catch (Throwable $error) {
            $this->reportStorage('signals', $error, ['active_ban_key' => $ban->key()]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentSignal(array $row): array
    {
        return [
            'uid' => (string) ($row['uid'] ?? ''),
            'occurred_at' => (string) ($row['occurred_at'] ?? ''),
            'signal_type' => (string) ($row['signal_type'] ?? ''),
            'reason_code' => (string) ($row['reason_code'] ?? ''),
            'severity' => (string) ($row['severity'] ?? ''),
            'confidence' => (int) ($row['confidence'] ?? 0),
            'request_id' => (string) ($row['request_id'] ?? ''),
            'visitor_id' => (string) ($row['visitor_id'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
            'route' => (string) ($row['route'] ?? ''),
            'http_status' => null === ($row['http_status'] ?? null) ? null : (int) $row['http_status'],
            'context' => $this->safeContext($row['context'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeContext(mixed $context): array
    {
        if (is_array($context)) {
            return $context;
        }

        if (!is_string($context) || '' === trim($context)) {
            return [];
        }

        $decoded = json_decode($context, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportStorage(string $operation, Throwable $error, array $context = []): void
    {
        try {
            $this->messageReporter?->report(Message::exception(
                SecurityMessageCode::AUTO_BAN_STORAGE_DEGRADED,
                SecurityMessageKey::AUTO_BAN_STORAGE_DEGRADED,
                context: [
                    'operation' => $operation,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                    ...$context,
                ],
            ), ['component' => self::class]);
        } catch (Throwable) {
        }
    }
}
