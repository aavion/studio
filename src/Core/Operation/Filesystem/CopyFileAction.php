<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunDiff;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final readonly class CopyFileAction implements OperationActionInterface
{
    private PathGuard $pathGuard;

    private string $sourceRelativePath;

    private string $targetRelativePath;

    public function __construct(
        private string $sourceRoot,
        string $sourceRelativePath,
        private string $targetRoot,
        string $targetRelativePath,
        private bool $overwrite = false,
        private bool $createParentDirectories = true,
        ?PathGuard $pathGuard = null,
    ) {
        $this->pathGuard = $pathGuard ?? new PathGuard();
        $this->sourceRelativePath = $this->pathGuard->relativePath($sourceRelativePath);
        $this->targetRelativePath = $this->pathGuard->relativePath($targetRelativePath);
    }

    public function type(): string
    {
        return 'copy_file';
    }

    public function label(): string
    {
        return sprintf('Copy file %s to %s', $this->sourceRelativePath, $this->targetRelativePath);
    }

    public function dryRun(): DryRunAction
    {
        $source = $this->sourcePath();
        $target = $this->targetPath();
        $sourceIsSymlink = is_link($source);
        $targetIsSymlink = is_link($target);
        $sourceExists = !$sourceIsSymlink && is_file($source);
        $targetExists = !$targetIsSymlink && is_file($target);
        $sourceContents = $sourceExists ? (string) file_get_contents($source) : '';
        $targetContents = $targetExists ? (string) file_get_contents($target) : '';

        return DryRunAction::create($this->type(), $this->label(), $targetExists ? DryRunRisk::Medium : DryRunRisk::Low, [
            $this->sourceRelativePath,
            $this->targetRelativePath,
        ], [
            DryRunDiff::text($this->targetRelativePath, $targetContents, $sourceContents),
        ], [
            'source' => $this->sourceRelativePath,
            'target' => $this->targetRelativePath,
            'source_exists' => $sourceExists,
            'target_exists' => $targetExists,
            'source_is_symlink' => $sourceIsSymlink,
            'target_is_symlink' => $targetIsSymlink,
            'overwrite' => $this->overwrite,
            'create_parent_directories' => $this->createParentDirectories,
        ]);
    }

    /**
     * @return OperationResult<array{source: string, target: string, bytes: int, overwritten: bool}>
     */
    public function execute(): OperationResult
    {
        $source = $this->sourcePath();
        $target = $this->targetPath();

        if (is_link($source)) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_SOURCE_SYMLINK, MessageKey::FILESYSTEM_SOURCE_SYMLINK, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ]),
            ]);
        }

        if (!is_file($source)) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_SOURCE_MISSING, MessageKey::FILESYSTEM_SOURCE_MISSING, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ]),
            ]);
        }

        if (is_link($target)) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_TARGET_SYMLINK, MessageKey::FILESYSTEM_TARGET_SYMLINK, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ]),
            ]);
        }

        if (is_dir($target)) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_FILE_CONFLICT, MessageKey::FILESYSTEM_FILE_CONFLICT, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ]),
            ]);
        }

        $targetExists = file_exists($target);

        if ($targetExists && !$this->overwrite) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_FILE_EXISTS, MessageKey::FILESYSTEM_FILE_EXISTS, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ]),
            ]);
        }

        $parentResult = $this->ensureParentDirectory($target);

        if (!$parentResult->isSuccess()) {
            return $parentResult;
        }

        if (!copy($source, $target)) {
            return OperationResult::failed([
                OperationIssue::create(MessageCode::FILESYSTEM_FILE_COPY_FAILED, MessageKey::FILESYSTEM_FILE_COPY_FAILED, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ]),
            ]);
        }

        $bytes = filesize($target);

        return OperationResult::success([
            'source' => $this->sourceRelativePath,
            'target' => $this->targetRelativePath,
            'bytes' => false === $bytes ? 0 : $bytes,
            'overwritten' => $targetExists,
        ], [
            'source' => $this->sourceRelativePath,
            'target' => $this->targetRelativePath,
            'bytes' => false === $bytes ? 0 : $bytes,
            'overwritten' => $targetExists,
        ]);
    }

    private function sourcePath(): string
    {
        return $this->pathGuard->join($this->sourceRoot, $this->sourceRelativePath);
    }

    private function targetPath(): string
    {
        return $this->pathGuard->join($this->targetRoot, $this->targetRelativePath);
    }

    /**
     * @return OperationResult<null>
     */
    private function ensureParentDirectory(string $target): OperationResult
    {
        $parent = dirname($target);
        $symlinkAncestor = $this->pathGuard->symlinkAncestor($this->targetRoot, $this->targetRelativePath);

        if (null !== $symlinkAncestor) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_PARENT_SYMLINK, MessageKey::FILESYSTEM_PARENT_SYMLINK, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => $symlinkAncestor,
                ]),
            ]);
        }

        if (is_dir($parent)) {
            return OperationResult::success();
        }

        if (!$this->createParentDirectories) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_PARENT_MISSING, MessageKey::FILESYSTEM_PARENT_MISSING, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => dirname($this->targetRelativePath),
                ]),
            ]);
        }

        if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
            return OperationResult::failed([
                OperationIssue::create(MessageCode::FILESYSTEM_PARENT_CREATE_FAILED, MessageKey::FILESYSTEM_PARENT_CREATE_FAILED, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => dirname($this->targetRelativePath),
                ]),
            ]);
        }

        return OperationResult::success();
    }
}
