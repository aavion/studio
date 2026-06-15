<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Geo\GeoIpMessageCode;
use App\Core\Geo\MaxMindGeoIpArchiveExtractor;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class MaxMindGeoIpArchiveExtractorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $workspaceDir;

    protected function setUp(): void
    {
        $this->workspaceDir = $this->createTemporaryDirectory('maxmind-geoip-archive');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspaceDir);
    }

    public function testItExtractsReadableDatabaseFromSafeArchive(): void
    {
        $archivePath = $this->archivePath('safe', [
            'GeoLite2-City/GeoLite2-City.mmdb' => 'database',
        ]);

        $result = (new MaxMindGeoIpArchiveExtractor())->extractDatabase($archivePath, $this->workspaceDir);

        self::assertTrue($result->isSuccess());
        self::assertSame('database', file_get_contents($result->value()['database_path']));
    }

    public function testItRejectsUnsafeArchivePaths(): void
    {
        $archivePath = $this->archivePath('unsafe', [
            '../escape.mmdb' => 'database',
        ]);

        $result = (new MaxMindGeoIpArchiveExtractor())->extractDatabase($archivePath, $this->workspaceDir);

        self::assertFalse($result->isSuccess());
        self::assertSame(GeoIpMessageCode::GEOIP_DOWNLOAD_ARCHIVE_INVALID, $result->firstIssue()?->code());
        self::assertSame('unsafe_archive_path', $result->context()['reason'] ?? null);
        self::assertFileDoesNotExist(dirname($this->workspaceDir).DIRECTORY_SEPARATOR.'escape.mmdb');
    }

    public function testItRejectsUnsupportedArchiveEntryTypes(): void
    {
        $archivePath = $this->archivePath('unsupported-type', [
            'GeoLite2-City/link' => ['type' => '2', 'link' => '/tmp'],
        ]);

        $result = (new MaxMindGeoIpArchiveExtractor())->extractDatabase($archivePath, $this->workspaceDir);

        self::assertFalse($result->isSuccess());
        self::assertSame(GeoIpMessageCode::GEOIP_DOWNLOAD_ARCHIVE_INVALID, $result->firstIssue()?->code());
        self::assertSame('unsafe_archive_path', $result->context()['reason'] ?? null);
    }

    /**
     * @param array<string, string|array{contents?: string, type?: string, link?: string}> $files
     */
    private function archivePath(string $name, array $files): string
    {
        $archivePath = $this->workspaceDir.DIRECTORY_SEPARATOR.$name.'.tar.gz';
        file_put_contents($archivePath, gzencode($this->tarContents($files)));

        return $archivePath;
    }

    /**
     * @param array<string, string|array{contents?: string, type?: string, link?: string}> $files
     */
    private function tarContents(array $files): string
    {
        $tar = '';

        foreach ($files as $path => $entry) {
            $contents = is_array($entry) ? (string) ($entry['contents'] ?? '') : $entry;
            $type = is_array($entry) ? (string) ($entry['type'] ?? '0') : '0';
            $link = is_array($entry) ? (string) ($entry['link'] ?? '') : '';
            $tar .= $this->tarHeader($path, strlen($contents), $type, $link);
            $tar .= $contents;
            $tar .= str_repeat("\0", (512 - (strlen($contents) % 512)) % 512);
        }

        return $tar.str_repeat("\0", 1024);
    }

    private function tarHeader(string $path, int $size, string $type = '0', string $link = ''): string
    {
        $header = str_pad(substr($path, 0, 100), 100, "\0");
        $header .= str_pad('0000644', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad(decoct(0), 11, '0', STR_PAD_LEFT)."\0";
        $header .= str_repeat(' ', 8);
        $header .= $type[0] ?? '0';
        $header .= str_pad(substr($link, 0, 100), 100, "\0");
        $header .= "ustar\0";
        $header .= "00";
        $header .= str_repeat("\0", 247);
        $header = str_pad($header, 512, "\0");
        $checksum = 0;

        for ($index = 0; $index < 512; ++$index) {
            $checksum += ord($header[$index]);
        }

        return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }
}
