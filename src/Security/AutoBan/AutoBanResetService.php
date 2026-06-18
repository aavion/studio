<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

final readonly class AutoBanResetService
{
    public function __construct(
        private AutoBanStore $store,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param callable(ActiveAutoBan): bool $recordResetSignal
     */
    public function releaseAndRecord(string $key, callable $recordResetSignal): ?ActiveAutoBan
    {
        $released = $this->store->reset($key);
        if (!$released instanceof ActiveAutoBan) {
            return null;
        }

        try {
            if ($recordResetSignal($released)) {
                return $released;
            }
        } catch (Throwable) {
        }

        $this->restore($released);

        return null;
    }

    private function restore(ActiveAutoBan $ban): void
    {
        $subject = new AutoBanSubject(
            $ban->subjectType(),
            $ban->subjectIdentifier(),
            AutoBanSubject::IP === $ban->subjectType(),
        );

        $this->store->createOrReturnActive($subject, $ban->retryAfterSeconds($this->clock->now()), [
            ...$ban->context(),
            'restored_after_failed_reset_signal' => true,
        ]);
    }
}
