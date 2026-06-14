<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Mercure\MercureRuntime;
use App\Core\Process\DetachedProcessStarter;
use DateTimeImmutable;
use Throwable;

final readonly class MercureAvailability
{
    public const ENABLED_KEY = 'integration.mercure.enabled';
    public const AVAILABLE_KEY = 'integration.mercure.available';
    public const CHECKED_AT_KEY = 'integration.mercure.checked_at';
    public const CHECK_INTERVAL_SECONDS = 3600;
    private const STARTUP_WAIT_MICROSECONDS = 500000;

    public function __construct(
        private Config $config,
        private MercureRuntime $runtime,
        private DetachedProcessStarter $starter,
        private string $projectDir,
    ) {
    }

    public function enabled(): bool
    {
        return true === $this->config->get(self::ENABLED_KEY, true);
    }

    public function available(bool $refreshIfStale = false): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        if ($refreshIfStale && $this->isStale()) {
            return $this->refresh(recover: true);
        }

        return true === $this->config->get(self::AVAILABLE_KEY, false);
    }

    public function refresh(bool $recover = true): bool
    {
        return $this->refreshStatus($recover)['available'];
    }

    /**
     * @return array{available: bool, enabled: bool, publish: bool, public: bool, started: bool, stopped: bool}
     */
    public function refreshStatus(bool $recover = true): array
    {
        if (!$this->enabled()) {
            $this->store(false);

            return [
                'available' => false,
                'enabled' => false,
                'publish' => false,
                'public' => false,
                'started' => false,
                'stopped' => false,
            ];
        }

        $publishAvailable = $this->runtime->publishHealthProbe();
        $started = false;
        $stopped = false;

        if (!$publishAvailable && $recover && $this->startHub()) {
            $started = true;
            usleep(self::STARTUP_WAIT_MICROSECONDS);
            $publishAvailable = $this->runtime->publishHealthProbe();
        }

        if ($publishAvailable) {
            if ($this->runtime->publicSubscribeProbe()) {
                $this->store(true);

                return [
                    'available' => true,
                    'enabled' => true,
                    'publish' => true,
                    'public' => true,
                    'started' => $started,
                    'stopped' => false,
                ];
            }

            $stopped = $this->runtime->stop();
        }

        $this->store(false);

        return [
            'available' => false,
            'enabled' => true,
            'publish' => $publishAvailable,
            'public' => false,
            'started' => $started,
            'stopped' => $stopped,
        ];
    }

    private function isStale(): bool
    {
        $checkedAt = $this->config->get(self::CHECKED_AT_KEY);
        if (!is_string($checkedAt) || '' === trim($checkedAt)) {
            return true;
        }

        try {
            $checked = new DateTimeImmutable($checkedAt);
        } catch (Throwable) {
            return true;
        }

        return time() - $checked->getTimestamp() >= self::CHECK_INTERVAL_SECONDS;
    }

    private function store(bool $available): void
    {
        $this->config->set(self::AVAILABLE_KEY, $available, ConfigValueType::Boolean);
        $this->config->set(self::CHECKED_AT_KEY, (new DateTimeImmutable())->format(DATE_ATOM), ConfigValueType::String);
    }

    private function startHub(): bool
    {
        if (!$this->runtime->canStart()) {
            return false;
        }

        try {
            return $this->starter->start(
                $this->runtime->startCommand(),
                $this->projectDir,
                $this->runtime->logPath(),
                $this->runtime->pidPath(),
                $this->runtime->startEnvironment(),
            );
        } catch (Throwable) {
            return false;
        }
    }
}
