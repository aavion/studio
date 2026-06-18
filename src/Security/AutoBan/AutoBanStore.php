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
        $lock = $this->lockFactory->createLock(self::KEY_PREFIX.$subject->key(), 5.0);

        try {
            if (!$lock->acquire()) {
                return $this->active($subject);
            }

            if (null !== ($active = $this->active($subject))) {
                return $active;
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
                $this->cache->deleteItem($this->cacheKey($ban->key()));

                return null;
            }

            return $ban;
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
        try {
            $ban = $this->activeByKey($key);
            $this->cache->deleteItem($this->cacheKey($key));
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
