<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Id\UuidFactory;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Database\DatabaseReadyState;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

final readonly class AutoBanSignalEvaluator
{
    private const TABLE = 'security_signal_event';

    public function __construct(
        private Connection $connection,
        private DatabaseLogRetentionPolicy $retentionPolicy,
        private AutoBanPolicy $policy,
        private AutoBanScoreCatalogue $scores,
        private AutoBanStore $store,
        private ?DatabaseReadyState $databaseReadyState = null,
        private string $environment = 'prod',
        private ?MessageReporterInterface $messageReporter = null,
        private ?AutoBanOwnerAlertNotifier $ownerAlerts = null,
        private UuidFactory $uuidFactory = new UuidFactory(),
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param array<string, mixed> $signal
     */
    public function afterSignalRecorded(array $signal): void
    {
        try {
            if (!$this->enabled() || !$this->policy->enabled() || (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady())) {
                return;
            }

            if (true === ($signal['auto_ban_exempt'] ?? false)) {
                return;
            }

            $subjectType = (string) ($signal['subject_type'] ?? '');
            $subjectIdentifier = (string) ($signal['subject_identifier'] ?? '');
            if (!$this->scores->scoreableSubject($subjectType) || '' === $subjectIdentifier) {
                return;
            }

            $score = $this->scores->scoreFor(
                (string) ($signal['signal_type'] ?? ''),
                (string) ($signal['reason_code'] ?? ''),
                isset($signal['http_status']) ? (int) $signal['http_status'] : null,
            );

            if ($score <= 0) {
                return;
            }

            $subject = new AutoBanSubject($subjectType, $subjectIdentifier, AutoBanSubject::IP === $subjectType);
            if (AutoBanSubject::IP === $subjectType && $this->banTriggeredForRequest((string) ($signal['request_id'] ?? ''))) {
                return;
            }

            if (null !== $this->store->active($subject)) {
                return;
            }

            $summary = $this->scoreSummary($subject);
            if ($summary['signal_count'] < AutoBanPolicy::MINIMUM_QUALIFYING_SIGNALS || $summary['score'] < $this->policy->thresholdFor($subjectType)) {
                return;
            }

            $priorBanSignals = $this->priorBanSignalCount($subject);
            $ttlSeconds = $this->policy->ttlForEscalationCount($priorBanSignals);
            $banResult = $this->store->createOrReturnActive($subject, $ttlSeconds, [
                'score' => $summary['score'],
                'signal_count' => $summary['signal_count'],
                'threshold' => $this->policy->thresholdFor($subjectType),
                'window_seconds' => AutoBanPolicy::SCORE_WINDOW_SECONDS,
                'escalation_index' => $priorBanSignals,
            ]);

            if (null === $banResult || !$banResult->created()) {
                return;
            }

            $ban = $banResult->ban();
            if (!$this->recordBanTriggeredSignal($subject, $ban, $summary, $signal, $priorBanSignals)) {
                $this->store->reset($ban->key());

                return;
            }

            $this->ownerAlerts?->notifyBanTriggered($ban);
        } catch (Throwable $error) {
            $this->reportEvaluation('after_signal_recorded', $error, [
                'reason_code' => (string) ($signal['reason_code'] ?? 'n/a'),
                'subject_type' => (string) ($signal['subject_type'] ?? 'n/a'),
            ]);

            return;
        }
    }

    private function enabled(): bool
    {
        return 'test' !== $this->environment;
    }

    /**
     * @return array{score: int, signal_count: int}
     */
    public function scoreSummary(AutoBanSubject $subject): array
    {
        $now = $this->clock->now();
        $windowStart = $now->modify('-'.AutoBanPolicy::SCORE_WINDOW_SECONDS.' seconds');
        $resetAt = $this->latestResetAt($subject);
        if ($resetAt instanceof DateTimeImmutable && $resetAt > $windowStart) {
            $windowStart = $resetAt;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT signal_type, reason_code, http_status FROM '.self::TABLE.' WHERE subject_type = ? AND subject_identifier = ? AND occurred_at > ? AND occurred_at <= ? AND expires_at > ? ORDER BY occurred_at ASC',
            [
                $subject->type(),
                $subject->identifier(),
                $windowStart->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $score = 0;
        $count = 0;
        foreach ($rows as $row) {
            $weight = $this->scores->scoreFor(
                (string) ($row['signal_type'] ?? ''),
                (string) ($row['reason_code'] ?? ''),
                null === ($row['http_status'] ?? null) ? null : (int) $row['http_status'],
            );
            if ($weight <= 0) {
                continue;
            }

            $score += $weight;
            ++$count;
        }

        return ['score' => $score, 'signal_count' => $count];
    }

    private function priorBanSignalCount(AutoBanSubject $subject): int
    {
        $resetAt = $this->latestResetAt($subject);
        $parameters = [$subject->type(), $subject->identifier(), AutoBanScoreCatalogue::SIGNAL_TRIGGERED, $this->clock->now()->format('Y-m-d H:i:s')];
        $where = 'subject_type = ? AND subject_identifier = ? AND reason_code = ? AND expires_at > ?';

        if ($resetAt instanceof DateTimeImmutable) {
            $where .= ' AND occurred_at > ?';
            $parameters[] = $resetAt->format('Y-m-d H:i:s');
        }

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.self::TABLE.' WHERE '.$where, $parameters);
    }

    private function banTriggeredForRequest(string $requestId): bool
    {
        if ('' === $requestId || 'n/a' === $requestId) {
            return false;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM '.self::TABLE.' WHERE request_id = ? AND reason_code = ?',
            [$requestId, AutoBanScoreCatalogue::SIGNAL_TRIGGERED],
        ) > 0;
    }

    private function latestResetAt(AutoBanSubject $subject): ?DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT occurred_at FROM '.self::TABLE.' WHERE subject_type = ? AND subject_identifier = ? AND reason_code = ? ORDER BY occurred_at DESC LIMIT 1',
            [$subject->type(), $subject->identifier(), AutoBanScoreCatalogue::SIGNAL_RESET],
        );

        if (!is_string($value) || '' === $value) {
            return null;
        }

        $parsed = $this->timestamp($value);
        if (null === $parsed) {
            $this->reportInvalidPayload([
                'payload' => 'reset_signal_timestamp',
                'subject_type' => $subject->type(),
            ]);

            return null;
        }

        return $parsed;
    }

    /**
     * @param array{score: int, signal_count: int} $summary
     * @param array<string, mixed> $sourceSignal
     */
    private function recordBanTriggeredSignal(
        AutoBanSubject $subject,
        ActiveAutoBan $ban,
        array $summary,
        array $sourceSignal,
        int $priorBanSignals,
    ): bool {
        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval('P'.$this->retentionPolicy->retentionDaysForSignal().'D'));
        $context = [
            'effective_subject_type' => $subject->effectiveType(),
            'active_ban_key' => $ban->key(),
            'score' => $summary['score'],
            'signal_count' => $summary['signal_count'],
            'threshold' => $this->policy->thresholdFor($subject->type()),
            'ttl_seconds' => $ban->ttlSeconds(),
            'expires_at' => $ban->expiresAt()->format('Y-m-d H:i:s'),
            'escalation_index' => $priorBanSignals,
            'source_request_id' => $sourceSignal['request_id'] ?? 'n/a',
            'source_reason_code' => $sourceSignal['reason_code'] ?? 'n/a',
        ];

        try {
            $this->connection->insert(self::TABLE, [
                'uid' => $this->uuidFactory->generate(),
                'occurred_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'signal_type' => 'auto_ban',
                'reason_code' => AutoBanScoreCatalogue::SIGNAL_TRIGGERED,
                'severity' => 'WARNING',
                'confidence' => 100,
                'subject_type' => $subject->type(),
                'subject_identifier' => $subject->identifier(),
                'ip_derived' => $subject->ipDerived() ? 1 : 0,
                'request_family' => (string) ($sourceSignal['request_family'] ?? 'unknown'),
                'request_intent' => (string) ($sourceSignal['request_intent'] ?? 'unknown'),
                'request_id' => (string) ($sourceSignal['request_id'] ?? 'n/a'),
                'visitor_id' => (string) ($sourceSignal['visitor_id'] ?? 'n/a'),
                'path' => (string) ($sourceSignal['path'] ?? 'n/a'),
                'route' => (string) ($sourceSignal['route'] ?? 'n/a'),
                'http_status' => null,
                'context' => $this->json($context),
            ]);

            return true;
        } catch (Throwable $error) {
            $this->reportEvaluation('record_ban_triggered_signal', $error, [
                'active_ban_key' => $ban->key(),
                'subject_type' => $subject->type(),
            ]);

            return false;
        }
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value || str_contains($value, "\0")) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable
            && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))
                ? $parsed
                : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportEvaluation(string $operation, Throwable $error, array $context = []): void
    {
        try {
            $this->messageReporter?->report(Message::exception(
                SecurityMessageCode::AUTO_BAN_EVALUATION_DEGRADED,
                SecurityMessageKey::AUTO_BAN_EVALUATION_DEGRADED,
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

    /**
     * @param array<string, mixed> $context
     */
    private function reportInvalidPayload(array $context): void
    {
        try {
            $this->messageReporter?->report(Message::warning(
                SecurityMessageCode::AUTO_BAN_PAYLOAD_INVALID,
                SecurityMessageKey::AUTO_BAN_PAYLOAD_INVALID,
                context: $context,
            ), ['component' => self::class]);
        } catch (Throwable) {
        }
    }
}
