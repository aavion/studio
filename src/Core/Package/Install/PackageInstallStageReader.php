<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\WorkflowResult;

final readonly class PackageInstallStageReader
{
    public function __construct(
        private PackageInstallFilesystem $filesystem,
        private ManifestParser $manifestParser = new ManifestParser(),
    ) {
    }

    private const IGNORED_STAGE_ENTRIES = ['.', '..', '__MACOSX'];

    /**
     * @return WorkflowResult<Manifest>
     */
    public function readManifest(string $packageRoot): WorkflowResult
    {
        $path = $packageRoot.DIRECTORY_SEPARATOR.'.manifest';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (!is_string($contents)) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_MANIFEST_UNREADABLE,
                    MessageKey::PACKAGE_MANIFEST_UNREADABLE,
                    ['%path%' => $this->filesystem->relativePath($path)],
                    ['path' => $this->filesystem->relativePath($path)],
                ),
            ]);
        }

        return $this->manifestParser->parse($contents);
    }

    public function packageRoot(string $stagePath): ?string
    {
        if (is_file($stagePath.DIRECTORY_SEPARATOR.'.manifest')) {
            return $stagePath;
        }

        $children = array_values(array_filter(
            scandir($stagePath) ?: [],
            static fn (string $entry): bool => !in_array($entry, self::IGNORED_STAGE_ENTRIES, true),
        ));

        if (1 !== count($children)) {
            return null;
        }

        $child = $stagePath.DIRECTORY_SEPARATOR.$children[0];

        return is_dir($child) && is_file($child.DIRECTORY_SEPARATOR.'.manifest') ? $child : null;
    }
}
