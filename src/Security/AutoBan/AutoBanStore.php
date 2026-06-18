<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Throwable;

final readonly class AutoBanStore
{
    private const KEY_PREFIX = 'security.auto_ban.active.';
    private const INDEX_KEY = 'security.auto_ban.index.v1';
    private const INDEX_LOCK_KEY = 'security.auto_ban.index.lock';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LockFactory $lockFactory,
        private ?MessageReporterInterface $messageReporter = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function ban(AutoBanSubject $subject, int $ttlSeconds, array $context = []): ?ActiveAutoBan
    {
        return $this->createOrReturnActive($subject, $ttlSeconds, $context)?->ban();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function createOrReturnActive(AutoBanSubject $subject, int $ttlSeconds, array $context = []): ?AutoBanStoreResult
    {
        $lock = $this->lockFactory->createLock(self::KEY_PREFIX.$subject->key(), 5.0);

        try {
            if (!$lock->acquire()) {
                $active = $this->active($subject);

                return $active instanceof ActiveAutoBan ? new AutoBanStoreResult($active, false) : null;
            }

            if (null !== ($active = $this->active($subject))) {
                return new AutoBanStoreResult($active, false);
            }

            $now = $this->clock->now();
            $ban = new ActiveAutoBan(
                $subject->key(),
                $subject->type(),
                $subject->identifier(),
                $now,
                $now->modify('+'.$ttlSeconds.' seconds'),
                $ttlSeconds,
                $context,
            );

            $item = $this->cache->getItem($this->cacheKey($ban->key()));
            $item->set($ban->toArray());
            $item->expiresAfter($ttlSeconds);
            if (!$this->cache->save($item)) {
                return null;
            }

            if (!$this->upsertIndex($ban)) {
                $this->rollbackActiveState($ban);

                return null;
            }

            return new AutoBanStoreResult($ban, true);
        } catch (Throwable $error) {
            $this->reportStorage('ban', $error, ['active_ban_key' => $subject->key()]);

            return null;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $error) {
                $this->reportStorage('lock_release', $error, ['active_ban_key' => $subject->key()]);
            }
        }
    }

    public function active(AutoBanSubject $subject): ?ActiveAutoBan
    {
        return $this->activeByKey($subject->key());
    }

    public function activeByKey(string $key): ?ActiveAutoBan
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($key));
            if (!$item->isHit()) {
                return null;
            }

            $payload = $item->get();
            $ban = ActiveAutoBan::fromArray(is_array($payload) ? $payload : []);
            if (null === $ban || $ban->expiresAt() <= $this->clock->now()) {
                if (null === $ban && is_array($payload)) {
                    $this->reportInvalidPayload($key, $payload);
                }
                $this->cache->deleteItem($this->cacheKey($key));

                return null;
            }

            return $ban;
        } catch (Throwable $error) {
            $this->reportStorage('active_lookup', $error, ['active_ban_key' => $key]);

            return null;
        }
    }

    public function reset(string $key): ?ActiveAutoBan
    {
        return $this->withSubjectLock($key, fn (): ?ActiveAutoBan => $this->resetUnlocked($key), 'reset');
    }

    /**
     * @param callable(ActiveAutoBan): bool $recordResetSignal
     */
    public function resetAndRecord(string $key, callable $recordResetSignal): ?ActiveAutoBan
    {
        return $this->withSubjectLock($key, function () use ($key, $recordResetSignal): ?ActiveAutoBan {
            $ban = $this->resetUnlocked($key);
            if (!$ban instanceof ActiveAutoBan) {
                return null;
            }

            try {
                if ($recordResetSignal($ban)) {
                    return $ban;
                }
            } catch (Throwable $error) {
                $this->reportStorage('reset_signal', $error, ['active_ban_key' => $key]);
            }

            $this->restoreActiveState($ban, [
                ...$ban->context(),
                'restored_after_failed_reset_signal' => true,
            ]);

            return null;
        }, 'reset_cutoff');
    }

    private function resetUnlocked(string $key): ?ActiveAutoBan
    {
        try {
            $ban = $this->activeByKey($key);
            if (!$ban instanceof ActiveAutoBan) {
                $this->removeIndex($key);

                return null;
            }

            if (!$this->cache->deleteItem($this->cacheKey($key))) {
                $this->reportStorage('reset_delete', new \RuntimeException('Active auto-ban cache delete failed.'), ['active_ban_key' => $key]);

                return null;
            }

            if (null !== $this->activeByKey($key)) {
                $this->reportStorage('reset_verify', new \RuntimeException('Active auto-ban cache entry remained after delete.'), ['active_ban_key' => $key]);

                return null;
            }

            $this->removeIndex($key);

            return $ban;
        } catch (Throwable $error) {
            $this->reportStorage('reset', $error, ['active_ban_key' => $key]);

            return null;
        }
    }

    /**
     * @return list<ActiveAutoBan>
     */
    public function activeBans(): array
    {
        try {
            $index = $this->index();
            $active = [];

            foreach (array_keys($index) as $key) {
                $ban = $this->activeByKey((string) $key);
                if (null === $ban) {
                    $this->removeIndex((string) $key);
                    continue;
                }

                $active[] = $ban;
            }

            usort(
                $active,
                static fn (ActiveAutoBan $left, ActiveAutoBan $right): int => $right->createdAt() <=> $left->createdAt(),
            );

            return $active;
        } catch (Throwable $error) {
            $this->reportStorage('active_list', $error);

            return [];
        }
    }

    private function cacheKey(string $key): string
    {
        return self::KEY_PREFIX.$key;
    }

    private function rollbackActiveState(ActiveAutoBan $ban): void
    {
        $cacheKey = $this->cacheKey($ban->key());
        if (!$this->cache->deleteItem($cacheKey)) {
            $this->reportStorage('ban_rollback_delete', new \RuntimeException('Active auto-ban rollback cache delete failed.'), ['active_ban_key' => $ban->key()]);
        }

        if (null === $this->activeByKey($ban->key())) {
            return;
        }

        $item = $this->cache->getItem($cacheKey);
        $payload = $ban->toArray();
        $payload['expires_at'] = $this->clock->now()->modify('-1 second')->format('Y-m-d H:i:s');
        $item->set($payload);
        $item->expiresAfter($ban->ttlSeconds());
        if (!$this->cache->save($item) || null !== $this->activeByKey($ban->key())) {
            $this->reportStorage('ban_rollback_verify', new \RuntimeException('Active auto-ban cache entry remained after rollback.'), ['active_ban_key' => $ban->key()]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function restoreActiveState(ActiveAutoBan $ban, array $context): void
    {
        $restored = new ActiveAutoBan(
            $ban->key(),
            $ban->subjectType(),
            $ban->subjectIdentifier(),
            $ban->createdAt(),
            $ban->expiresAt(),
            $ban->ttlSeconds(),
            $context,
        );

        try {
            $item = $this->cache->getItem($this->cacheKey($restored->key()));
            $item->set($restored->toArray());
            $item->expiresAfter($restored->retryAfterSeconds($this->clock->now()));
            if (!$this->cache->save($item)) {
                $this->reportStorage('reset_restore_save', new \RuntimeException('Active auto-ban restore cache save failed.'), ['active_ban_key' => $restored->key()]);

                return;
            }

            if (!$this->upsertIndex($restored)) {
                $this->rollbackActiveState($restored);
            }
        } catch (Throwable $error) {
            $this->reportStorage('reset_restore', $error, ['active_ban_key' => $restored->key()]);
        }
    }

    /**
     * @param callable(): ?ActiveAutoBan $operation
     */
    private function withSubjectLock(string $key, callable $operation, string $operationName): ?ActiveAutoBan
    {
        $lock = $this->lockFactory->createLock(self::KEY_PREFIX.$key, 5.0);

        try {
            if (!$lock->acquire(true)) {
                $this->reportStorage($operationName, new \RuntimeException('Auto-ban subject lock unavailable.'), ['active_ban_key' => $key]);

                return null;
            }

            return $operation();
        } catch (Throwable $error) {
            $this->reportStorage($operationName, $error, ['active_ban_key' => $key]);

            return null;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $error) {
                $this->reportStorage($operationName.'_lock_release', $error, ['active_ban_key' => $key]);
            }
        }
    }

    private function upsertIndex(ActiveAutoBan $ban): bool
    {
        return $this->updateIndex(static function (array $index) use ($ban): array {
            $index[$ban->key()] = [
                'subject_type' => $ban->subjectType(),
                'subject_label' => $ban->subjectLabel(),
                'expires_at' => $ban->expiresAt()->format('Y-m-d H:i:s'),
            ];

            return $index;
        }, 'index_upsert', ['active_ban_key' => $ban->key()]);
    }

    private function removeIndex(string $key): bool
    {
        return $this->updateIndex(static function (array $index) use ($key): array {
            unset($index[$key]);

            return $index;
        }, 'index_remove', ['active_ban_key' => $key]);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @param array<string, mixed>                                  $context
     */
    private function updateIndex(callable $mutator, string $operation, array $context = []): bool
    {
        $lock = $this->lockFactory->createLock(self::INDEX_LOCK_KEY, 5.0);

        try {
            if (!$lock->acquire(true)) {
                $this->reportStorage($operation, new \RuntimeException('Auto-ban index lock unavailable.'), $context);

                return false;
            }

            return $this->saveIndex($mutator($this->index()));
        } catch (Throwable $error) {
            $this->reportStorage($operation, $error, $context);

            return false;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $error) {
                $this->reportStorage('index_lock_release', $error, $context);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function index(): array
    {
        $item = $this->cache->getItem(self::INDEX_KEY);

        return $item->isHit() && is_array($item->get()) ? $item->get() : [];
    }

    /**
     * @param array<string, mixed> $index
     */
    private function saveIndex(array $index): bool
    {
        $item = $this->cache->getItem(self::INDEX_KEY);
        $item->set($index);
        $item->expiresAfter(604800);

        return $this->cache->save($item);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function reportInvalidPayload(string $key, array $payload): void
    {
        try {
            $this->messageReporter?->report(Message::warning(
                SecurityMessageCode::AUTO_BAN_PAYLOAD_INVALID,
                SecurityMessageKey::AUTO_BAN_PAYLOAD_INVALID,
                context: [
                    'active_ban_key' => $key,
                    'payload_fields' => array_values(array_filter(
                        array_keys($payload),
                        static fn (mixed $field): bool => is_string($field),
                    )),
                ],
            ), ['component' => self::class]);
        } catch (Throwable) {
        }
    }
}
