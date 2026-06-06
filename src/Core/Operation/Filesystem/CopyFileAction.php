<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunDiff;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Filesystem\FilesystemMessageCode;
use App\Core\Operation\Filesystem\FilesystemMessageKey;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

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
     * @return WorkflowResult<array{source: string, target: string, bytes: int, overwritten: bool}>
     */
    public function execute(): WorkflowResult
    {
        $source = $this->sourcePath();
        $target = $this->targetPath();

        if (is_link($source)) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_SOURCE_SYMLINK, FilesystemMessageKey::FILESYSTEM_SOURCE_SYMLINK, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (!is_file($source)) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_SOURCE_MISSING, FilesystemMessageKey::FILESYSTEM_SOURCE_MISSING, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_link($target)) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_TARGET_SYMLINK, FilesystemMessageKey::FILESYSTEM_TARGET_SYMLINK, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_dir($target)) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_FILE_CONFLICT, FilesystemMessageKey::FILESYSTEM_FILE_CONFLICT, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        $targetExists = file_exists($target);

        if ($targetExists && !$this->overwrite) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_FILE_EXISTS, FilesystemMessageKey::FILESYSTEM_FILE_EXISTS, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        $parentResult = $this->ensureParentDirectory($target);

        if (!$parentResult->isSuccess()) {
            return $parentResult;
        }

        if (!copy($source, $target)) {
            return WorkflowResult::failed([
                Message::create(FilesystemMessageCode::FILESYSTEM_FILE_COPY_FAILED, FilesystemMessageKey::FILESYSTEM_FILE_COPY_FAILED, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                ], level: MessageLevel::Error),
            ]);
        }

        $bytes = filesize($target);

        return WorkflowResult::success([
            'source' => $this->sourceRelativePath,
            'target' => $this->targetRelativePath,
            'bytes' => false === $bytes ? 0 : $bytes,
            'overwritten' => $targetExists,
        ], [
            'source' => $this->sourceRelativePath,
            'target' => $this->targetRelativePath,
            'bytes' => false === $bytes ? 0 : $bytes,
            'overwritten' => $targetExists,
        ], [
            ...$parentResult->messages(),
            Message::debug(FilesystemMessageCode::FILESYSTEM_FILE_COPIED, FilesystemMessageKey::FILESYSTEM_FILE_COPIED, [
                '%target%' => $this->targetRelativePath,
            ], [
                'source' => $this->sourceRelativePath,
                'target' => $this->targetRelativePath,
                'bytes' => false === $bytes ? 0 : $bytes,
                'overwritten' => $targetExists,
            ]),
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
     * @return WorkflowResult<null>
     */
    private function ensureParentDirectory(string $target): WorkflowResult
    {
        $parent = dirname($target);
        $symlinkAncestor = $this->pathGuard->symlinkAncestor($this->targetRoot, $this->targetRelativePath);

        if (null !== $symlinkAncestor) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_PARENT_SYMLINK, FilesystemMessageKey::FILESYSTEM_PARENT_SYMLINK, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => $symlinkAncestor,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_dir($parent)) {
            return WorkflowResult::success(messages: [
                Message::debug(FilesystemMessageCode::FILESYSTEM_PARENT_DIRECTORY_READY, FilesystemMessageKey::FILESYSTEM_PARENT_DIRECTORY_READY, [
                    '%path%' => dirname($this->targetRelativePath),
                ], [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => dirname($this->targetRelativePath),
                    'created' => false,
                ]),
            ]);
        }

        if (!$this->createParentDirectories) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_PARENT_MISSING, FilesystemMessageKey::FILESYSTEM_PARENT_MISSING, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => dirname($this->targetRelativePath),
                ], level: MessageLevel::Warning),
            ]);
        }

        if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
            return WorkflowResult::failed([
                Message::create(FilesystemMessageCode::FILESYSTEM_PARENT_CREATE_FAILED, FilesystemMessageKey::FILESYSTEM_PARENT_CREATE_FAILED, context: [
                    'source' => $this->sourceRelativePath,
                    'target' => $this->targetRelativePath,
                    'parent' => dirname($this->targetRelativePath),
                ], level: MessageLevel::Error),
            ]);
        }

        return WorkflowResult::success(messages: [
            Message::debug(FilesystemMessageCode::FILESYSTEM_PARENT_DIRECTORY_READY, FilesystemMessageKey::FILESYSTEM_PARENT_DIRECTORY_READY, [
                '%path%' => dirname($this->targetRelativePath),
            ], [
                'source' => $this->sourceRelativePath,
                'target' => $this->targetRelativePath,
                'parent' => dirname($this->targetRelativePath),
                'created' => true,
            ]),
        ]);
    }
}
