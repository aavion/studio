<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Core\Id\UuidFactory;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Database\DatabaseReadyState;
use App\Security\AutoBan\AutoBanSignalEvaluator;
use DateInterval;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

final readonly class SecuritySignalRecorder
{
    private const TABLE = 'security_signal_event';
    private const PLACEHOLDER = 'n/a';

    public function __construct(
        private Connection $connection,
        private DatabaseLogRetentionPolicy $retentionPolicy,
        private ?DatabaseReadyState $databaseReadyState = null,
        private UuidFactory $uuidFactory = new UuidFactory(),
        private ClockInterface $clock = new NativeClock(),
        private ?AutoBanSignalEvaluator $autoBanSignals = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(
        string $signalType,
        string $reasonCode,
        string $subjectType,
        string $subjectIdentifier,
        bool $ipDerived = false,
        string $severity = 'INFO',
        int $confidence = 50,
        string $requestFamily = 'unknown',
        string $requestIntent = 'unknown',
        string $requestId = self::PLACEHOLDER,
        string $visitorId = self::PLACEHOLDER,
        string $path = self::PLACEHOLDER,
        string $route = self::PLACEHOLDER,
        ?int $httpStatus = null,
        array $context = [],
    ): void {
        if (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady()) {
            return;
        }

        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval('P'.$this->retentionPolicy->retentionDaysForSignal().'D'));
        $context = [
            ...$context,
            'signal_type' => $this->short($signalType, 80),
            'reason_code' => $this->short($reasonCode, 120),
            'subject_type' => $this->short($subjectType, 40),
            'subject_identifier' => $this->short($subjectIdentifier, 190),
            'ip_derived' => $ipDerived,
            'request_family' => $this->short($requestFamily, 40),
            'request_intent' => $this->short($requestIntent, 80),
        ];

        try {
            $row = [
                'uid' => $this->uuidFactory->generate(),
                'occurred_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'signal_type' => $context['signal_type'],
                'reason_code' => $context['reason_code'],
                'severity' => $this->severity($severity),
                'confidence' => max(0, min(100, $confidence)),
                'subject_type' => $context['subject_type'],
                'subject_identifier' => $context['subject_identifier'],
                'ip_derived' => $ipDerived ? 1 : 0,
                'request_family' => $context['request_family'],
                'request_intent' => $context['request_intent'],
                'request_id' => $this->short($requestId, 64),
                'visitor_id' => $this->short($visitorId, 64),
                'path' => $this->short($path, 1024),
                'route' => $this->short($route, 190),
                'http_status' => $httpStatus,
                'context' => $this->json($context),
            ];

            $this->connection->insert(self::TABLE, $row);
            $this->purgeExpired();
            $this->autoBanSignals?->afterSignalRecorded([...$row, ...$context]);
        } catch (Throwable) {
            return;
        }
    }

    public function purgeExpired(): int
    {
        try {
            return $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE expires_at <= ?', [
                $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            return 0;
        }
    }

    private function short(mixed $value, int $length): string
    {
        $value = is_scalar($value) ? trim((string) $value) : self::PLACEHOLDER;

        return mb_substr('' === $value ? self::PLACEHOLDER : $value, 0, $length);
    }

    private function severity(string $severity): string
    {
        $severity = strtoupper(trim($severity));

        return in_array($severity, ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'], true) ? $severity : 'INFO';
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
