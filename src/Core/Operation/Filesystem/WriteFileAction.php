<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunDiff;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final readonly class WriteFileAction implements OperationActionInterface
{
    private PathGuard $pathGuard;

    private string $relativePath;

    public function __construct(
        private string $root,
        string $relativePath,
        private string $contents,
        private bool $overwrite = false,
        private bool $createParentDirectories = true,
        ?PathGuard $pathGuard = null,
    ) {
        $this->pathGuard = $pathGuard ?? new PathGuard();
        $this->relativePath = $this->pathGuard->relativePath($relativePath);
    }

    public function type(): string
    {
        return 'write_file';
    }

    public function label(): string
    {
        return sprintf('Write file %s', $this->relativePath);
    }

    public function dryRun(): DryRunAction
    {
        $target = $this->targetPath();
        $isSymlink = is_link($target);
        $exists = !$isSymlink && is_file($target);
        $before = $exists ? (string) file_get_contents($target) : '';

        return DryRunAction::create($this->type(), $this->label(), $exists ? DryRunRisk::Medium : DryRunRisk::Low, [$this->relativePath], [
            DryRunDiff::text($this->relativePath, $before, $this->contents),
        ], [
            'exists' => $exists,
            'target_is_symlink' => $isSymlink,
            'overwrite' => $this->overwrite,
            'create_parent_directories' => $this->createParentDirectories,
            'target' => $this->relativePath,
        ]);
    }

    /**
     * @return OperationResult<array{path: string, bytes: int, overwritten: bool}>
     */
    public function execute(): OperationResult
    {
        $target = $this->targetPath();
        $exists = file_exists($target);

        if (is_link($target)) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_TARGET_SYMLINK, MessageKey::FILESYSTEM_TARGET_SYMLINK, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_dir($target)) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_FILE_CONFLICT, MessageKey::FILESYSTEM_FILE_CONFLICT, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if ($exists && !$this->overwrite) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_FILE_EXISTS, MessageKey::FILESYSTEM_FILE_EXISTS, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        $parentResult = $this->ensureParentDirectory($target);

        if (!$parentResult->isSuccess()) {
            return $parentResult;
        }

        $bytes = file_put_contents($target, $this->contents, LOCK_EX);

        if (false === $bytes) {
            return OperationResult::failed([
                OperationIssue::create(MessageCode::FILESYSTEM_FILE_WRITE_FAILED, MessageKey::FILESYSTEM_FILE_WRITE_FAILED, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Error),
            ]);
        }

        return OperationResult::success([
            'path' => $this->relativePath,
            'bytes' => $bytes,
            'overwritten' => $exists,
        ], [
            'path' => $this->relativePath,
            'bytes' => $bytes,
            'overwritten' => $exists,
        ], [
            ...$parentResult->messages(),
            Message::debug(MessageCode::FILESYSTEM_FILE_WRITTEN, MessageKey::FILESYSTEM_FILE_WRITTEN, [
                '%path%' => $this->relativePath,
            ], [
                'path' => $this->relativePath,
                'bytes' => $bytes,
                'overwritten' => $exists,
            ]),
        ]);
    }

    private function targetPath(): string
    {
        return $this->pathGuard->join($this->root, $this->relativePath);
    }

    /**
     * @return OperationResult<null>
     */
    private function ensureParentDirectory(string $target): OperationResult
    {
        $parent = dirname($target);
        $symlinkAncestor = $this->pathGuard->symlinkAncestor($this->root, $this->relativePath);

        if (null !== $symlinkAncestor) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_PARENT_SYMLINK, MessageKey::FILESYSTEM_PARENT_SYMLINK, context: [
                    'path' => $this->relativePath,
                    'parent' => $symlinkAncestor,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_dir($parent)) {
            return OperationResult::success(messages: [
                Message::debug(MessageCode::FILESYSTEM_PARENT_DIRECTORY_READY, MessageKey::FILESYSTEM_PARENT_DIRECTORY_READY, [
                    '%path%' => dirname($this->relativePath),
                ], [
                    'path' => $this->relativePath,
                    'parent' => dirname($this->relativePath),
                    'created' => false,
                ]),
            ]);
        }

        if (!$this->createParentDirectories) {
            return OperationResult::blocked([
                OperationIssue::create(MessageCode::FILESYSTEM_PARENT_MISSING, MessageKey::FILESYSTEM_PARENT_MISSING, context: [
                    'path' => $this->relativePath,
                    'parent' => dirname($this->relativePath),
                ], level: MessageLevel::Warning),
            ]);
        }

        if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
            return OperationResult::failed([
                OperationIssue::create(MessageCode::FILESYSTEM_PARENT_CREATE_FAILED, MessageKey::FILESYSTEM_PARENT_CREATE_FAILED, context: [
                    'path' => $this->relativePath,
                    'parent' => dirname($this->relativePath),
                ], level: MessageLevel::Error),
            ]);
        }

        return OperationResult::success(messages: [
            Message::debug(MessageCode::FILESYSTEM_PARENT_DIRECTORY_READY, MessageKey::FILESYSTEM_PARENT_DIRECTORY_READY, [
                '%path%' => dirname($this->relativePath),
            ], [
                'path' => $this->relativePath,
                'parent' => dirname($this->relativePath),
                'created' => true,
            ]),
        ]);
    }
}
