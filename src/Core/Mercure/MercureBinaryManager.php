<?php

declare(strict_types=1);

namespace App\Core\Mercure;

use PharData;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

final readonly class MercureBinaryManager
{
    public const DEFAULT_VERSION = '0.24.2';

    public function __construct(
        private string $projectDir,
        private string $version = self::DEFAULT_VERSION,
    ) {
    }

    public function binaryPath(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.$this->binaryName();
    }

    public function isInstalled(): bool
    {
        $path = $this->binaryPath();

        return is_file($path) && is_executable($path);
    }

    public function install(): bool
    {
        if ($this->isInstalled()) {
            return true;
        }

        $asset = $this->assetName();
        if (null === $asset) {
            return false;
        }

        $archivePath = $this->cacheDir().DIRECTORY_SEPARATOR.$asset;

        try {
            $this->ensureDirectory($this->cacheDir());
            $this->ensureDirectory($this->installDir());

            if (!is_file($archivePath)) {
                $response = HttpClient::create(['timeout' => 30])->request('GET', $this->downloadUrl($asset));
                file_put_contents($archivePath, $response->getContent(), LOCK_EX);
            }

            $this->extract($archivePath);
            $binary = $this->binaryPath();
            if (!is_file($binary)) {
                return false;
            }

            @chmod($binary, 0755);
            $this->releaseMacQuarantine($binary);

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
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'Darwin',
            'Linux' => 'Linux',
            'Windows' => 'Windows',
            default => null,
        };
        $arch = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            'armv6l' => 'armv6',
            'i386', 'i686' => 'i386',
            default => null,
        };

        if (null === $os || null === $arch) {
            return null;
        }

        $extension = 'Windows' === $os ? 'zip' : 'tar.gz';

        return sprintf('mercure-legacy_%s_%s.%s', $os, $arch, $extension);
    }

    private function downloadUrl(string $asset): string
    {
        return sprintf('https://github.com/dunglas/mercure/releases/download/v%s/%s', $this->safeVersion(), $asset);
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

        $tarPath = preg_replace('/\.gz$/', '', $archivePath) ?: $archivePath.'.tar';
        if (!is_file($tarPath)) {
            $archive = new PharData($archivePath);
            $archive->decompress();
        }

        (new PharData($tarPath))->extractTo($this->installDir(), null, true);
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
