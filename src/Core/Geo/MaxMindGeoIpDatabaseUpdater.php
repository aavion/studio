<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use Throwable;

final readonly class MaxMindGeoIpDatabaseUpdater
{
    public function __construct(
        private MaxMindGeoIpConfig $config,
        private MaxMindGeoIpDownloadClientInterface $downloadClient,
        private MaxMindGeoIpArchiveExtractorInterface $archiveExtractor,
        private MaxMindGeoIpDatabaseReaderFactoryInterface $readerFactory,
        private string $projectDir,
    ) {
    }

    /**
     * @return WorkflowResult<array{database_path: string}>
     */
    public function update(string $trigger): WorkflowResult
    {
        $context = [
            'edition' => MaxMindGeoIpConfig::DATABASE_EDITION,
            'database_path' => $this->config->databasePath(),
            'trigger' => $this->trigger($trigger),
        ];

        if (!$this->config->hasLicenseKey()) {
            return WorkflowResult::failed([
                Message::error(
                    GeoIpMessageCode::GEOIP_DOWNLOAD_MISSING_LICENSE_KEY,
                    GeoIpMessageKey::GEOIP_DOWNLOAD_MISSING_LICENSE_KEY,
                    context: ['stage' => 'preflight', ...$context],
                ),
            ], ['stage' => 'preflight', ...$context]);
        }

        $targetPath = $this->config->databaseAbsolutePath($this->projectDir);
        if (null === $targetPath) {
            return WorkflowResult::failed([
                Message::error(
                    GeoIpMessageCode::GEOIP_DOWNLOAD_WRITE_FAILED,
                    GeoIpMessageKey::GEOIP_DOWNLOAD_WRITE_FAILED,
                    context: ['stage' => 'preflight', 'reason' => 'invalid_database_path', ...$context],
                ),
            ], ['stage' => 'preflight', 'reason' => 'invalid_database_path', ...$context]);
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return $this->writeFailure(['stage' => 'preflight', 'reason' => 'directory_create_failed', ...$context]);
        }

        $workspace = $this->workspace($targetDir);
        if (!is_dir($workspace) && !mkdir($workspace, 0775, true) && !is_dir($workspace)) {
            return $this->writeFailure(['stage' => 'preflight', 'reason' => 'workspace_create_failed', ...$context]);
        }

        try {
            $archivePath = $workspace.DIRECTORY_SEPARATOR.'maxmind.tar.gz';
            $download = $this->downloadClient->download($this->config->downloadUrl(), $archivePath);
            if (!$download->isSuccess()) {
                return $this->forwardFailure($download, $context);
            }

            $extract = $this->archiveExtractor->extractDatabase($archivePath, $workspace);
            if (!$extract->isSuccess()) {
                return $this->forwardFailure($extract, $context);
            }

            $candidate = $extract->value()['database_path'] ?? null;
            if (!is_string($candidate) || !$this->databaseLooksValid($candidate)) {
                return WorkflowResult::failed([
                    Message::error(
                        GeoIpMessageCode::GEOIP_DOWNLOAD_DATABASE_INVALID,
                        GeoIpMessageKey::GEOIP_DOWNLOAD_DATABASE_INVALID,
                        context: ['stage' => 'validate', ...$context],
                    ),
                ], ['stage' => 'validate', ...$context]);
            }

            if (!$this->replaceDatabase($candidate, $targetPath)) {
                return $this->writeFailure(['stage' => 'replace', ...$context]);
            }
        } finally {
            $this->removeDirectory($workspace);
        }

        return WorkflowResult::success(['database_path' => $this->config->databasePath()], $context, [
            Message::success(GeoIpMessageKey::GEOIP_DOWNLOAD_COMPLETED, [
                '%path%' => $this->config->databasePath(),
            ], $context),
        ]);
    }

    private function databaseLooksValid(string $candidate): bool
    {
        if (!is_file($candidate) || !is_readable($candidate)) {
            return false;
        }

        try {
            $metadata = $this->readerFactory->open($candidate, $this->config->locales())->metadata();
        } catch (Throwable) {
            return false;
        }

        return is_string($metadata->databaseType)
            && str_contains($metadata->databaseType, 'City');
    }

    private function replaceDatabase(string $candidate, string $targetPath): bool
    {
        $targetDir = dirname($targetPath);
        $temporaryTarget = tempnam($targetDir, '.geoip2-');
        if (!is_string($temporaryTarget)) {
            return false;
        }

        if (!@copy($candidate, $temporaryTarget)) {
            @unlink($temporaryTarget);

            return false;
        }

        if (@rename($temporaryTarget, $targetPath)) {
            return true;
        }

        if (!is_file($targetPath)) {
            @unlink($temporaryTarget);

            return false;
        }

        $backupPath = $targetPath.'.previous-'.bin2hex(random_bytes(4));
        if (!@rename($targetPath, $backupPath)) {
            @unlink($temporaryTarget);

            return false;
        }

        if (@rename($temporaryTarget, $targetPath)) {
            @unlink($backupPath);

            return true;
        }

        @rename($backupPath, $targetPath);
        @unlink($temporaryTarget);

        return false;
    }

    /**
     * @param WorkflowResult<mixed> $result
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<null>
     */
    private function forwardFailure(WorkflowResult $result, array $context): WorkflowResult
    {
        return WorkflowResult::failed($result->issues(), [
            ...$context,
            ...$result->context(),
        ], $result->messages());
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<null>
     */
    private function writeFailure(array $context): WorkflowResult
    {
        return WorkflowResult::failed([
            Message::error(
                GeoIpMessageCode::GEOIP_DOWNLOAD_WRITE_FAILED,
                GeoIpMessageKey::GEOIP_DOWNLOAD_WRITE_FAILED,
                context: $context,
            ),
        ], $context);
    }

    private function workspace(string $targetDir): string
    {
        return $targetDir.DIRECTORY_SEPARATOR.'.update-'.bin2hex(random_bytes(8));
    }

    private function trigger(string $trigger): string
    {
        return '' !== trim($trigger) ? trim($trigger) : 'system';
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
