<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final readonly class MaxMindGeoIpArchiveExtractor implements MaxMindGeoIpArchiveExtractorInterface
{
    public function extractDatabase(string $archivePath, string $workspaceDir): WorkflowResult
    {
        $extractDir = $workspaceDir.DIRECTORY_SEPARATOR.'extract';
        $tarPath = preg_replace('/\.gz$/', '', $archivePath) ?? ($archivePath.'.tar');

        if (!is_dir($extractDir) && !mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_WRITE_FAILED,
                GeoIpMessageKey::GEOIP_DOWNLOAD_WRITE_FAILED,
                ['stage' => 'extract'],
            );
        }

        try {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }

            $archive = new PharData($archivePath);
            $archive->decompress();
            $tar = new PharData($tarPath);
            $tar->extractTo($extractDir, null, true);
        } catch (Throwable $error) {
            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_ARCHIVE_INVALID,
                GeoIpMessageKey::GEOIP_DOWNLOAD_ARCHIVE_INVALID,
                ['stage' => 'extract', 'exception' => $error::class],
            );
        } finally {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
        }

        $databasePath = $this->findDatabase($extractDir);
        if (null === $databasePath) {
            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_DATABASE_MISSING,
                GeoIpMessageKey::GEOIP_DOWNLOAD_DATABASE_MISSING,
                ['stage' => 'extract'],
            );
        }

        return WorkflowResult::success(['database_path' => $databasePath], ['stage' => 'extract']);
    }

    private function findDatabase(string $extractDir): ?string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.mmdb') && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<null>
     */
    private function failure(string $code, string $key, array $context): WorkflowResult
    {
        return WorkflowResult::failed([
            Message::error($code, $key, context: $context),
        ], $context);
    }
}
