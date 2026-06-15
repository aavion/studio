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
            if (!$this->tarPathsAreSafe($tarPath)) {
                return $this->failure(
                    GeoIpMessageCode::GEOIP_DOWNLOAD_ARCHIVE_INVALID,
                    GeoIpMessageKey::GEOIP_DOWNLOAD_ARCHIVE_INVALID,
                    ['stage' => 'extract', 'reason' => 'unsafe_archive_path'],
                );
            }

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

    private function tarPathsAreSafe(string $tarPath): bool
    {
        $handle = @fopen($tarPath, 'rb');
        if (!is_resource($handle)) {
            return false;
        }

        try {
            while (!feof($handle)) {
                $header = fread($handle, 512);
                if (!is_string($header) || '' === $header) {
                    break;
                }

                if (512 !== strlen($header)) {
                    return false;
                }

                if (str_repeat("\0", 512) === $header) {
                    return true;
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $path = '' === $prefix ? $name : $prefix.'/'.$name;
                if (!$this->pathIsSafe($path)) {
                    return false;
                }

                $size = octdec(trim(rtrim(substr($header, 124, 12), "\0 ")) ?: '0');
                if ($size > 0) {
                    $skip = (int) (ceil($size / 512) * 512);
                    if (0 !== fseek($handle, $skip, SEEK_CUR)) {
                        return false;
                    }
                }
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    private function pathIsSafe(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return '' !== trim($path)
            && !str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && 1 !== preg_match('/^[A-Za-z]:\//', $path)
            && !str_contains('/'.$path.'/', '/../')
            && !str_contains($path, "\0");
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
