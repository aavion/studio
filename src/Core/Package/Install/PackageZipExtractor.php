<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Message\Message;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use ZipArchive;

final readonly class PackageZipExtractor
{
    private const ZIP_UNIX_FILE_TYPE_MASK = 0o170000;
    private const ZIP_UNIX_SYMLINK_TYPE = 0o120000;

    public function __construct(private PackageInstallFilesystem $filesystem)
    {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function extract(string $zipPath, string $stagePath): WorkflowResult
    {
        if (!class_exists(ZipArchive::class)) {
            return WorkflowResult::failed([
                Message::error(
                    PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: ['reason' => 'zip_extension_missing'],
                ),
            ]);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if (true !== $opened) {
            return WorkflowResult::invalid([
                Message::warning(
                    PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: ['reason' => 'open_failed', 'zip_error' => $opened],
                ),
            ]);
        }

        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $this->unsafeEntry($name)) {
                    return WorkflowResult::invalid([
                        Message::warning(
                            PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                            PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                            context: ['reason' => 'unsafe_entry', 'entry' => $name],
                        ),
                    ]);
                }

                if ($this->symlinkEntry($zip, $index)) {
                    return WorkflowResult::invalid([
                        Message::warning(
                            PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                            PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                            context: ['reason' => 'symlink_entry', 'entry' => $name],
                        ),
                    ]);
                }
            }

            $this->filesystem->removePath($stagePath);
            $this->filesystem->ensureDirectory($stagePath);

            if (!$zip->extractTo($stagePath)) {
                return WorkflowResult::failed([
                    Message::error(
                        PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                        PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                        context: ['reason' => 'extract_failed', 'zip_path' => $this->filesystem->relativePath($zipPath)],
                    ),
                ]);
            }

            $symlink = $this->filesystem->firstSymlinkPath($stagePath);
            if (null !== $symlink) {
                return WorkflowResult::invalid([
                    Message::warning(
                        PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                        PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                        context: [
                            'reason' => 'symlink_entry',
                            'entry' => $this->filesystem->relativePath($symlink),
                        ],
                    ),
                ]);
            }
        } finally {
            $zip->close();
        }

        return WorkflowResult::success([
            'stage_path' => $this->filesystem->relativePath($stagePath),
        ]);
    }

    private function symlinkEntry(ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $operatingSystem = 0;
        $attributes = 0;

        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }

        $mode = ($attributes >> 16) & self::ZIP_UNIX_FILE_TYPE_MASK;

        return self::ZIP_UNIX_SYMLINK_TYPE === $mode;
    }

    private function unsafeEntry(string $entry): bool
    {
        $normalized = str_replace('\\', '/', $entry);

        return str_starts_with($normalized, '/')
            || str_contains($normalized, '../')
            || str_starts_with($normalized, '../')
            || str_contains($normalized, "\0");
    }
}
