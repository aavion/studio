<?php

declare(strict_types=1);

namespace App\Core\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Internal\QueryBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class MercureRuntime
{
    private const DEFAULT_LISTEN_ADDRESS = '127.0.0.1:3000';

    public function __construct(
        private MercureBinaryManager $binaryManager,
        private HubInterface $hub,
        private string $defaultUri,
        private string $projectDir,
    ) {
    }

    /**
     * @return list<string>
     */
    public function startCommand(): array
    {
        $jwtSecret = $this->jwtSecret();

        return [
            $this->binaryManager->binaryPath(),
            '--addr',
            $this->listenAddress(),
            '--publisher-jwt-key',
            $jwtSecret,
            '--subscriber-jwt-key',
            $jwtSecret,
            '--cors-allowed-origins',
            '*',
            '--transport-url',
            $this->transportUrl(),
        ];
    }

    public function logPath(): string
    {
        return $this->projectDir.'/var/log/mercure.log';
    }

    public function pidPath(): string
    {
        return $this->projectDir.'/var/mercure/mercure.pid';
    }

    public function binaryPath(): string
    {
        return $this->binaryManager->binaryPath();
    }

    public function binaryInstalled(): bool
    {
        return $this->binaryManager->isInstalled();
    }

    public function processId(): ?int
    {
        $pid = $this->pid();

        return null !== $pid && $this->isProcessRunning($pid) ? $pid : null;
    }

    public function isRunning(): bool
    {
        $pid = $this->pid();

        if (null !== $pid && $this->isProcessRunning($pid)) {
            return true;
        }

        return [] !== $this->binaryProcessIds();
    }

    public function hubReachable(): bool
    {
        $url = $this->localHubUrl();

        return $this->hubEndpointProbe($url) || $this->publishDirectly($url);
    }

    public function canStart(): bool
    {
        return $this->binaryManager->isInstalled() || $this->binaryManager->install();
    }

    public function stop(): bool
    {
        $pid = $this->pid();
        if (null === $pid) {
            if ($this->terminateBinaryProcesses()) {
                $this->removePidFile();

                return true;
            }

            $this->removePidFile();

            return [] === $this->binaryProcessIds();
        }

        if (!$this->isProcessRunning($pid)) {
            if ($this->terminateBinaryProcesses()) {
                $this->removePidFile();

                return true;
            }

            $this->removePidFile();

            return [] === $this->binaryProcessIds();
        }

        if (!$this->terminate($pid)) {
            if ($this->terminateBinaryProcesses()) {
                $this->removePidFile();

                return true;
            }

            return false;
        }

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            usleep(100000);
            if (!$this->isProcessRunning($pid)) {
                $this->removePidFile();

                return true;
            }
        }

        if ($this->isProcessRunning($pid)) {
            return false;
        }

        if ([] !== $this->binaryProcessIds() && !$this->terminateBinaryProcesses()) {
            return false;
        }

        $this->removePidFile();

        return true;
    }

    public function publishHealthProbe(): bool
    {
        return $this->publishDirectly($this->publishHubUrl());
    }

    public function publicSubscribeProbe(): bool
    {
        $url = $this->publicHubUrl();
        if ('' === $url) {
            return false;
        }

        return $this->hubEndpointProbe($url);
    }

    public function listenAddress(): string
    {
        $listen = trim((string) ($_SERVER['MERCURE_HUB_LISTEN'] ?? $_ENV['MERCURE_HUB_LISTEN'] ?? getenv('MERCURE_HUB_LISTEN') ?: ''));
        if ('' !== $listen) {
            return $listen;
        }

        $url = (string) ($_SERVER['MERCURE_URL'] ?? $_ENV['MERCURE_URL'] ?? getenv('MERCURE_URL') ?: '');
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (is_string($host) && '' !== $host && is_int($port)) {
            $defaultHost = parse_url($this->defaultUri, PHP_URL_HOST);
            $defaultPort = parse_url($this->defaultUri, PHP_URL_PORT) ?? ('https' === parse_url($this->defaultUri, PHP_URL_SCHEME) ? 443 : 80);
            if ($host === $defaultHost && $port === $defaultPort) {
                return self::DEFAULT_LISTEN_ADDRESS;
            }

            return $host.':'.$port;
        }

        return self::DEFAULT_LISTEN_ADDRESS;
    }

    public function localHubUrl(): string
    {
        $listen = $this->listenAddress();
        if (str_starts_with($listen, 'http://') || str_starts_with($listen, 'https://')) {
            return rtrim($listen, '/').'/.well-known/mercure';
        }

        return 'http://'.$listen.'/.well-known/mercure';
    }

    public function publishHubUrl(): string
    {
        $url = trim((string) ($_SERVER['MERCURE_URL'] ?? $_ENV['MERCURE_URL'] ?? getenv('MERCURE_URL') ?: ''));

        return '' !== $url ? $url : $this->localHubUrl();
    }

    public function publicHubUrl(): string
    {
        $url = trim((string) ($_SERVER['MERCURE_PUBLIC_URL'] ?? $_ENV['MERCURE_PUBLIC_URL'] ?? getenv('MERCURE_PUBLIC_URL') ?: ''));

        return '' !== $url ? $url : $this->localHubUrl();
    }

    private function publishDirectly(string $url): bool
    {
        try {
            if (!method_exists($this->hub, 'getProvider')) {
                return false;
            }

            $response = HttpClient::create(['timeout' => 2.0])->request('POST', $url, [
                'auth_bearer' => $this->hub->getProvider()->getJwt(),
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => QueryBuilder::build([
                    'topic' => $this->healthTopic(),
                    'data' => '{}',
                    'type' => 'ui-alert-health',
                ]),
            ]);

            $response->getContent();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hubEndpointProbe(string $url): bool
    {
        try {
            $response = HttpClient::create([
                'timeout' => 2.0,
                'max_duration' => 2.0,
            ])->request('GET', $url);
            $body = $response->getContent(false);
            $status = $response->getStatusCode();

            if (400 === $status) {
                return str_contains($body, 'Missing "topic" parameter')
                    || str_contains($body, 'Missing topic parameter')
                    || str_contains($body, 'missing "topic" parameter')
                    || str_contains($body, 'missing topic parameter');
            }

            return 401 === $status
                && (
                    str_contains($body, 'Unauthorized')
                    || str_contains($body, 'unauthorized')
                );
        } catch (Throwable) {
            return false;
        }
    }

    private function jwtSecret(): string
    {
        $secret = $_SERVER['MERCURE_JWT_SECRET']
            ?? $_ENV['MERCURE_JWT_SECRET']
            ?? getenv('MERCURE_JWT_SECRET')
            ?: null;

        if (is_string($secret) && '' !== $secret) {
            return $secret;
        }

        $appSecret = $_SERVER['APP_SECRET']
            ?? $_ENV['APP_SECRET']
            ?? getenv('APP_SECRET')
            ?: '';

        return (string) $appSecret;
    }

    private function healthTopic(): string
    {
        return rtrim($this->defaultUri, '/').'/ui-alerts/health';
    }

    private function urlWithTopic(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.QueryBuilder::build(['topic' => $this->healthTopic()]);
    }

    private function transportUrl(): string
    {
        $directory = $this->projectDir.'/var/mercure';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $this->boltTransportUrl($directory.'/updates.db');
    }

    private function boltTransportUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (1 === preg_match('/^[A-Za-z]:\//', $normalized)) {
            $normalized = '/'.$normalized;
        }

        return 'bolt://'.$normalized.'?size=1000&cleanup_frequency=0.3';
    }

    private function pid(): ?int
    {
        $path = $this->pidPath();
        if (!is_file($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        return 1 === preg_match('/^\d+$/', $contents) ? (int) $contents : null;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }

    private function terminate(int $pid): bool
    {
        try {
            if ('\\' === DIRECTORY_SEPARATOR) {
                $process = new Process(['taskkill', '/PID', (string) $pid, '/T', '/F']);
            } else {
                $process = new Process(['kill', '-TERM', (string) $pid]);
            }
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<int>
     */
    private function binaryProcessIds(): array
    {
        $binary = $this->binaryManager->binaryPath();
        if (!is_file($binary)) {
            return [];
        }

        try {
            if ('\\' === DIRECTORY_SEPARATOR) {
                $script = 'Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -eq $args[0] } | ForEach-Object { $_.ProcessId }';
                $process = new Process(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $script, $binary]);
            } else {
                $process = new Process(['ps', '-eo', 'pid=,command=']);
            }

            $process->setTimeout(5);
            $process->run();

            if (!$process->isSuccessful()) {
                return [];
            }

            return '\\' === DIRECTORY_SEPARATOR
                ? $this->windowsProcessIds($process->getOutput())
                : $this->posixProcessIds($process->getOutput(), $binary);
        } catch (Throwable) {
            return [];
        }
    }

    private function terminateBinaryProcesses(): bool
    {
        $processIds = $this->binaryProcessIds();

        if ([] === $processIds) {
            return false;
        }

        $stopped = true;

        foreach ($processIds as $processId) {
            $stopped = $this->terminate($processId) && $stopped;
        }

        return $stopped;
    }

    /**
     * @return list<int>
     */
    private function windowsProcessIds(string $output): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): int => (int) trim($line), explode("\n", $output)),
            static fn (int $processId): bool => $processId > 0,
        ));
    }

    /**
     * @return list<int>
     */
    private function posixProcessIds(string $output, string $binary): array
    {
        $processIds = [];

        foreach (explode("\n", $output) as $line) {
            if (1 !== preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $command = trim($matches[2]);
            if (str_starts_with($command, $binary.' ') || $command === $binary) {
                $processIds[] = (int) $matches[1];
            }
        }

        return $processIds;
    }

    private function removePidFile(): void
    {
        $path = $this->pidPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
