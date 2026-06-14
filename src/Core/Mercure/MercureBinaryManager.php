<?php

declare(strict_types=1);

namespace App\Core\Mercure;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use ZipArchive;

final readonly class MercureBinaryManager
{
    public const DEFAULT_VERSION = '0.24.2';
    private const SHA256_BY_VERSION_AND_ASSET = [
        '0.24.2' => [
            'mercure_Darwin_arm64.tar.gz' => '69a22d63c30fb6820395eb0abdd0b2c48fa8bc4a6e799f6c7efc05d86a8e9b35',
            'mercure_Darwin_x86_64.tar.gz' => 'f726f9edbd721452ab3797546141934a760a3f70b376bb05484fb2540fc23381',
            'mercure_Linux_arm64.tar.gz' => '3917e836f94f5a0ba94effd7e4aabd73dfda4c6c52fbf379592331af8dcf33ab',
            'mercure_Linux_armv5.tar.gz' => '697b8d659493728f3b6c8702aa544eeb509a440d39ce225d1f921774ab6d25e9',
            'mercure_Linux_armv6.tar.gz' => '20a097869d9f5070491f67ca00a81030d6aa6632fb37069218032ca83fa35ef8',
            'mercure_Linux_armv7.tar.gz' => '617d8c7fc7fd934e385463c9413cf1fc61b6f5e8218d0c09855e797eeb356233',
            'mercure_Linux_i386.tar.gz' => 'e5254c1c03f1e180dbe12958336ebdc1efb1456ff9d3b6ec38309f6016aebf47',
            'mercure_Linux_x86_64.tar.gz' => '0447e2db7f7819692c72544f19371a93c4162a50d9fae849b3c99df50e212fd0',
            'mercure_Windows_arm64.zip' => 'b38ec4b39cb464d6578ec6c0639b6ab4ed1c8cf57fb722e6a5d9a8f2251e4bc8',
            'mercure_Windows_i386.zip' => '50ac0165b0c714c56d9bb434c739a088cb4c640c79ba2d2eea45507933e2449c',
            'mercure_Windows_x86_64.zip' => '6cf9330d079778cf6f118de68a55dca477685b4bbd23d560ab06b9fb7a547158',
        ],
    ];

    public function __construct(
        private string $projectDir,
        private string $version = self::DEFAULT_VERSION,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function binaryPath(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.$this->binaryName();
    }

    public function caddyfilePath(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.'Caddyfile';
    }

    public function isInstalled(): bool
    {
        $path = $this->binaryPath();

        return is_file($path)
            && is_executable($path)
            && is_file($this->assetMarkerPath())
            && trim((string) @file_get_contents($this->assetMarkerPath())) === $this->assetName();
    }

    public function install(): bool
    {
        if ($this->isInstalled()) {
            return true;
        }

        $asset = $this->assetName();
        $checksum = null === $asset ? null : $this->assetChecksum($asset);
        if (null === $asset || null === $checksum) {
            return false;
        }

        $archivePath = $this->cacheDir().DIRECTORY_SEPARATOR.$asset;

        try {
            $this->ensureDirectory($this->cacheDir());
            $this->ensureDirectory($this->installDir());

            if (!is_file($archivePath) || !$this->archiveChecksumMatches($archivePath, $checksum)) {
                @unlink($archivePath);
                $response = $this->httpClient()->request('GET', $this->downloadUrl($asset));
                file_put_contents($archivePath, $response->getContent(), LOCK_EX);
            }

            if (!$this->archiveChecksumMatches($archivePath, $checksum)) {
                @unlink($archivePath);

                return false;
            }

            $this->extract($archivePath);
            $binary = $this->binaryPath();
            if (!is_file($binary)) {
                return false;
            }

            @chmod($binary, 0755);
            $this->releaseMacQuarantine($binary);
            file_put_contents($this->assetMarkerPath(), (string) $asset, LOCK_EX);

            return $this->isInstalled();
        } catch (Throwable) {
            return false;
        }
    }

    private function installDir(): string
    {
        return $this->projectDir
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'mercure'
            .DIRECTORY_SEPARATOR.$this->safeVersion();
    }

    private function cacheDir(): string
    {
        return $this->projectDir
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'mercure'
            .DIRECTORY_SEPARATOR.'cache';
    }

    private function binaryName(): string
    {
        return '\\' === DIRECTORY_SEPARATOR ? 'mercure.exe' : 'mercure';
    }

    private function assetName(): ?string
    {
        return self::assetNameFor(PHP_OS_FAMILY, php_uname('m'));
    }

    private static function assetNameFor(string $osFamily, string $machine): ?string
    {
        $os = match ($osFamily) {
            'Darwin' => 'Darwin',
            'Linux' => 'Linux',
            'Windows' => 'Windows',
            default => null,
        };
        $arch = match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            'armv5', 'armv5l' => 'armv5',
            'armv6', 'armv6l' => 'armv6',
            'armv7', 'armv7l' => 'armv7',
            'i386', 'i686' => 'i386',
            default => null,
        };

        if (null === $os || null === $arch) {
            return null;
        }

        $extension = 'Windows' === $os ? 'zip' : 'tar.gz';

        return sprintf('mercure_%s_%s.%s', $os, $arch, $extension);
    }

    private function assetMarkerPath(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.'.asset-name';
    }

    private function downloadUrl(string $asset): string
    {
        return sprintf('https://github.com/dunglas/mercure/releases/download/v%s/%s', $this->safeVersion(), $asset);
    }

    private function assetChecksum(string $asset): ?string
    {
        return self::SHA256_BY_VERSION_AND_ASSET[$this->safeVersion()][$asset] ?? null;
    }

    private function archiveChecksumMatches(string $archivePath, string $expected): bool
    {
        return is_file($archivePath) && hash_equals($expected, hash_file('sha256', $archivePath) ?: '');
    }

    private function httpClient(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create(['timeout' => 30]);
    }

    private function safeVersion(): string
    {
        $version = trim($this->version);

        return 1 === preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9]+)?$/', $version)
            ? $version
            : self::DEFAULT_VERSION;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created.', $directory));
        }
    }

    private function extract(string $archivePath): void
    {
        if (str_ends_with($archivePath, '.zip')) {
            if (!class_exists(ZipArchive::class)) {
                return;
            }

            $zip = new ZipArchive();
            if (true !== $zip->open($archivePath)) {
                return;
            }

            $zip->extractTo($this->installDir());
            $zip->close();

            return;
        }

        $process = new Process(['tar', '-xzf', $archivePath, '-C', $this->installDir()]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Mercure archive could not be extracted.');
        }
    }

    private function releaseMacQuarantine(string $binary): void
    {
        if ('Darwin' !== PHP_OS_FAMILY) {
            return;
        }

        try {
            $process = new Process(['xattr', '-d', 'com.apple.quarantine', $binary]);
            $process->setTimeout(5);
            $process->run();
        } catch (Throwable) {
            return;
        }
    }
}
