<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunDiff;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
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
                OperationIssue::create('filesystem.target_symlink', 'Cannot write file because the target path is a symbolic link.', [
                    'path' => $this->relativePath,
                ]),
            ]);
        }

        if (is_dir($target)) {
            return OperationResult::blocked([
                OperationIssue::create('filesystem.file_conflict', 'Cannot write file because a directory already exists at the target path.', [
                    'path' => $this->relativePath,
                ]),
            ]);
        }

        if ($exists && !$this->overwrite) {
            return OperationResult::blocked([
                OperationIssue::create('filesystem.file_exists', 'Cannot write file because the target already exists and overwrite is disabled.', [
                    'path' => $this->relativePath,
                ]),
            ]);
        }

        $parentResult = $this->ensureParentDirectory($target);

        if (!$parentResult->isSuccess()) {
            return $parentResult;
        }

        $bytes = file_put_contents($target, $this->contents, LOCK_EX);

        if (false === $bytes) {
            return OperationResult::failed([
                OperationIssue::create('filesystem.file_write_failed', 'File could not be written.', [
                    'path' => $this->relativePath,
                ]),
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

        if (is_dir($parent)) {
            return OperationResult::success();
        }

        if (!$this->createParentDirectories) {
            return OperationResult::blocked([
                OperationIssue::create('filesystem.parent_missing', 'Cannot write file because the parent directory does not exist.', [
                    'path' => $this->relativePath,
                    'parent' => dirname($this->relativePath),
                ]),
            ]);
        }

        if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
            return OperationResult::failed([
                OperationIssue::create('filesystem.parent_create_failed', 'Parent directory could not be created.', [
                    'path' => $this->relativePath,
                    'parent' => dirname($this->relativePath),
                ]),
            ]);
        }

        return OperationResult::success();
    }
}
