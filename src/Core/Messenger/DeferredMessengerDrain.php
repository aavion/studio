<?php

declare(strict_types=1);

namespace App\Core\Messenger;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Process\PhpCliBinaryManager;
use App\Scheduler\SchedulerSettings;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Throwable;

final readonly class DeferredMessengerDrain
{
    private const DEFAULT_TRANSPORT = 'async';
    private const DEFAULT_COOLDOWN_SECONDS = 60;
    private const DEFAULT_MESSAGE_LIMIT = 25;
    private const DEFAULT_TIME_LIMIT_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private DeferredMessengerDrainStarterInterface $starter,
        private string $projectDir,
        private string $environment,
        private string $transportName = self::DEFAULT_TRANSPORT,
        private ?string $transportDsn = null,
        private int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
        private ?SchedulerSettings $schedulerSettings = null,
        private ?MessageLoggerInterface $messageLogger = null,
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
    ) {
    }

    public function drainPendingMessages(): bool
    {
        $drainMessenger = $this->hasPendingMessages();
        $runScheduler = $this->schedulerSettings?->enabled() === true
            && $this->schedulerSettings->webTriggerEnabled();

        if (!$drainMessenger && !$runScheduler) {
            return false;
        }

        $phpCliResolution = $this->phpCliBinaryManager->resolve($this->projectDir(), $this->safeEnvironment(), persistPreference: true);
        if (!$phpCliResolution->isAvailable()) {
            $this->messageLogger?->log(PhpCliUnavailableAction::message('deferred messenger drain', $phpCliResolution->reason(), [
                'drain_messenger' => $drainMessenger,
                'run_scheduler' => $runScheduler,
                'environment' => $this->safeEnvironment(),
            ]));

            return false;
        }

        $phpCliCommandPrefix = $phpCliResolution->commandPrefix();

        if (!$this->acquireCooldownLock()) {
            return false;
        }

        $messengerStarted = null;

        if ($drainMessenger) {
            $messengerStarted = $this->startDetached($this->messengerCommand($phpCliCommandPrefix), $this->outputPath(), $this->pidPath());
            if (!$messengerStarted) {
                $this->clearCooldownLock();

                return false;
            }
        }

        $schedulerStarted = null;
        if ($runScheduler) {
            $schedulerStarted = $this->startDetached($this->schedulerCommand($phpCliCommandPrefix), $this->schedulerOutputPath(), $this->schedulerPidPath());
        }

        if (true !== $messengerStarted && true !== $schedulerStarted) {
            $this->clearCooldownLock();

            return false;
        }

        return true === $messengerStarted || true === $schedulerStarted;
    }

    private function hasPendingMessages(): bool
    {
        try {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue_name AND delivered_at IS NULL AND available_at <= :now',
                [
                    'queue_name' => $this->doctrineQueueName(),
                    'now' => new DateTimeImmutable(),
                ],
                [
                    'queue_name' => ParameterType::STRING,
                    'now' => Types::DATETIME_IMMUTABLE,
                ],
            );
        } catch (Throwable) {
            return false;
        }

        return 0 < (int) $count;
    }

    private function doctrineQueueName(): string
    {
        $dsn = $this->transportDsn ?? $this->environmentTransportDsn();

        if ('' === $dsn) {
            return 'default';
        }

        $query = parse_url($dsn, PHP_URL_QUERY);

        if (!is_string($query) || '' === $query) {
            return 'default';
        }

        parse_str($query, $parameters);
        $queueName = $parameters['queue_name'] ?? null;

        if (!is_string($queueName) || '' === trim($queueName)) {
            return 'default';
        }

        return trim($queueName);
    }

    private function environmentTransportDsn(): string
    {
        $dsn = $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? $_ENV['MESSENGER_TRANSPORT_DSN'] ?? getenv('MESSENGER_TRANSPORT_DSN');

        return is_string($dsn) ? $dsn : '';
    }

    private function acquireCooldownLock(): bool
    {
        $path = $this->lockPath();

        try {
            if (is_file($path) && time() - filemtime($path) < $this->cooldownSeconds) {
                return false;
            }

            $directory = dirname($path);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return false;
            }

            return false !== file_put_contents($path, (string) time(), LOCK_EX);
        } catch (Throwable) {
            return false;
        }
    }

    private function clearCooldownLock(): void
    {
        try {
            $path = $this->lockPath();

            if (is_file($path)) {
                unlink($path);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @param list<string> $command
     */
    private function startDetached(array $command, string $outputPath, string $pidPath): bool
    {
        if ($this->starter->start($command, $this->projectDir(), $outputPath, $pidPath)) {
            return true;
        }

        $this->messageLogger?->log(Message::error(
            MessageCode::MESSENGER_DEFERRED_PROCESS_START_FAILED,
            MessageKey::MESSENGER_DEFERRED_PROCESS_START_FAILED,
            [],
            [
                'command' => $command,
                'output_path' => $outputPath,
                'pid_path' => $pidPath,
            ],
        ));

        return false;
    }

    /**
     * @return list<string>
     */
    private function messengerCommand(array $phpCliCommandPrefix): array
    {
        return [
            ...$phpCliCommandPrefix,
            $this->projectDir().'/bin/console',
            'messenger:consume',
            $this->transportName,
            '--limit='.self::DEFAULT_MESSAGE_LIMIT,
            '--time-limit='.self::DEFAULT_TIME_LIMIT_SECONDS,
            '--memory-limit=128M',
            '--env='.$this->safeEnvironment(),
            '--no-interaction',
        ];
    }

    /**
     * @return list<string>
     */
    private function schedulerCommand(array $phpCliCommandPrefix): array
    {
        return [
            ...$phpCliCommandPrefix,
            $this->projectDir().'/bin/scheduler',
            '--json',
            '--env='.$this->safeEnvironment(),
            '--no-interaction',
        ];
    }

    private function projectDir(): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR.'/\\');
    }

    private function lockPath(): string
    {
        return $this->projectDir()
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.$this->safeEnvironment()
            .DIRECTORY_SEPARATOR.'studio-messenger-drain.lock';
    }

    private function outputPath(): string
    {
        return $this->projectDir()
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'log'
            .DIRECTORY_SEPARATOR.$this->safeEnvironment()
            .DIRECTORY_SEPARATOR.'messenger-drain.log';
    }

    private function pidPath(): string
    {
        return $this->projectDir()
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.$this->safeEnvironment()
            .DIRECTORY_SEPARATOR.'studio-messenger-drain.pid';
    }

    private function schedulerOutputPath(): string
    {
        return $this->projectDir()
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'log'
            .DIRECTORY_SEPARATOR.$this->safeEnvironment()
            .DIRECTORY_SEPARATOR.'scheduler-web-trigger.log';
    }

    private function schedulerPidPath(): string
    {
        return $this->projectDir()
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.$this->safeEnvironment()
            .DIRECTORY_SEPARATOR.'studio-scheduler-web-trigger.pid';
    }

    private function safeEnvironment(): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $this->environment) ?: 'prod';
    }

}
