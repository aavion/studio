<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Security\AutoBan\ActiveAutoBan;
use App\Security\AutoBan\AutoBanResetService;
use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AutoBanResetServiceTest extends TestCase
{
    public function testItRecordsResetOnlyAfterActiveStateWasReleased(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-reset-service');
        $ban = $store->ban($subject, 3600);
        self::assertNotNull($ban);

        $released = (new AutoBanResetService($store, $clock))->releaseAndRecord($ban->key(), function (ActiveAutoBan $released) use ($store, $subject): bool {
            self::assertNull($store->active($subject));

            return $released->key() !== '';
        });

        self::assertInstanceOf(ActiveAutoBan::class, $released);
        self::assertNull($store->active($subject));
    }

    public function testItRestoresActiveStateWhenResetSignalCannotBeRecorded(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $subject = new AutoBanSubject(AutoBanSubject::IP, 'ip-reset-service', true);
        $ban = $store->ban($subject, 3600, ['score' => 100]);
        self::assertNotNull($ban);

        $released = (new AutoBanResetService($store, $clock))->releaseAndRecord($ban->key(), static fn (): bool => false);

        self::assertNull($released);
        $restored = $store->active($subject);
        self::assertInstanceOf(ActiveAutoBan::class, $restored);
        self::assertSame($ban->key(), $restored->key());
        self::assertTrue($restored->context()['restored_after_failed_reset_signal'] ?? false);
    }
}
