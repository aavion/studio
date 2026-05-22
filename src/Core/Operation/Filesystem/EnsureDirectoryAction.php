<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final readonly class EnsureDirectoryAction implements OperationActionInterface
{
    private PathGuard $pathGuard;

    private string $relativePath;

    public function __construct(
        private string $root,
        string $relativePath,
        private int $mode = 0775,
        ?PathGuard $pathGuard = null,
    ) {
        $this->pathGuard = $pathGuard ?? new PathGuard();
        $this->relativePath = $this->pathGuard->relativePath($relativePath);
    }

    public function type(): string
    {
        return 'ensure_directory';
    }

    public function label(): string
    {
        return sprintf('Ensure directory %s', $this->relativePath);
    }

    public function dryRun(): DryRunAction
    {
        $target = $this->targetPath();

        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Low, [$this->relativePath], context: [
            'exists' => is_dir($target),
            'mode' => sprintf('%04o', $this->mode),
            'target' => $this->relativePath,
        ]);
    }

    /**
     * @return OperationResult<array{path: string, created: bool}>
     */
    public function execute(): OperationResult
    {
        $target = $this->targetPath();
        $symlinkAncestor = $this->pathGuard->symlinkAncestor($this->root, $this->relativePath);

        if (is_link($target)) {
            return OperationResult::blocked([
                OperationIssue::create('filesystem.target_symlink', 'Cannot create directory because the target path is a symbolic link.', [
                    'path' => $this->relativePath,
                ]),
            ]);
        }

        if (null !== $symlinkAncestor) {
            return OperationResult::blocked([
                OperationIssue::create('filesystem.parent_symlink', 'Cannot create directory because a parent directory is a symbolic link.', [
                    'path' => $this->relativePath,
                    'parent' => $symlinkAncestor,
                ]),
            ]);
        }

        if (is_file($target)) {
            return OperationResult::blocked([
                OperationIssue::create('filesystem.directory_conflict', 'Cannot create directory because a file already exists at the target path.', [
                    'path' => $this->relativePath,
                ]),
            ]);
        }

        if (is_dir($target)) {
            return OperationResult::success([
                'path' => $this->relativePath,
                'created' => false,
            ], [
                'path' => $this->relativePath,
                'created' => false,
            ]);
        }

        if (!mkdir($target, $this->mode, true) && !is_dir($target)) {
            return OperationResult::failed([
                OperationIssue::create('filesystem.directory_create_failed', 'Directory could not be created.', [
                    'path' => $this->relativePath,
                ]),
            ]);
        }

        return OperationResult::success([
            'path' => $this->relativePath,
            'created' => true,
        ], [
            'path' => $this->relativePath,
            'created' => true,
        ]);
    }

    private function targetPath(): string
    {
        return $this->pathGuard->join($this->root, $this->relativePath);
    }
}
