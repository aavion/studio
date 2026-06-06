<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Filesystem\FilesystemMessageCode;
use App\Core\Operation\Filesystem\FilesystemMessageKey;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

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
     * @return WorkflowResult<array{path: string, created: bool}>
     */
    public function execute(): WorkflowResult
    {
        $target = $this->targetPath();
        $symlinkAncestor = $this->pathGuard->symlinkAncestor($this->root, $this->relativePath);

        if (is_link($target)) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_TARGET_SYMLINK, FilesystemMessageKey::FILESYSTEM_TARGET_SYMLINK, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (null !== $symlinkAncestor) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_PARENT_SYMLINK, FilesystemMessageKey::FILESYSTEM_PARENT_SYMLINK, context: [
                    'path' => $this->relativePath,
                    'parent' => $symlinkAncestor,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_file($target)) {
            return WorkflowResult::blocked([
                Message::create(FilesystemMessageCode::FILESYSTEM_DIRECTORY_CONFLICT, FilesystemMessageKey::FILESYSTEM_DIRECTORY_CONFLICT, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (is_dir($target)) {
            return WorkflowResult::success([
                'path' => $this->relativePath,
                'created' => false,
            ], [
                'path' => $this->relativePath,
                'created' => false,
            ], [
                Message::debug(FilesystemMessageCode::FILESYSTEM_DIRECTORY_READY, FilesystemMessageKey::FILESYSTEM_DIRECTORY_READY, [
                    '%path%' => $this->relativePath,
                ], [
                    'path' => $this->relativePath,
                    'created' => false,
                ]),
            ]);
        }

        if (!mkdir($target, $this->mode, true) && !is_dir($target)) {
            return WorkflowResult::failed([
                Message::create(FilesystemMessageCode::FILESYSTEM_DIRECTORY_CREATE_FAILED, FilesystemMessageKey::FILESYSTEM_DIRECTORY_CREATE_FAILED, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Error),
            ]);
        }

        return WorkflowResult::success([
            'path' => $this->relativePath,
            'created' => true,
            ], [
                'path' => $this->relativePath,
                'created' => true,
            ], [
                Message::create(FilesystemMessageCode::FILESYSTEM_DIRECTORY_READY, FilesystemMessageKey::FILESYSTEM_DIRECTORY_READY, [
                    '%path%' => $this->relativePath,
                ], [
                    'path' => $this->relativePath,
                    'created' => true,
                ], MessageLevel::Success),
            ]);
    }

    private function targetPath(): string
    {
        return $this->pathGuard->join($this->root, $this->relativePath);
    }
}
