<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class RemovePathAction implements OperationActionInterface
{
    private PathGuard $pathGuard;

    private string $relativePath;

    public function __construct(
        private string $root,
        string $relativePath,
        ?PathGuard $pathGuard = null,
    ) {
        $this->pathGuard = $pathGuard ?? new PathGuard();
        $this->relativePath = $this->pathGuard->relativePath($relativePath);
    }

    public function type(): string
    {
        return 'remove_path';
    }

    public function label(): string
    {
        return sprintf('Remove path %s', $this->relativePath);
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::High, [$this->relativePath], context: [
            'exists' => file_exists($this->targetPath()) || is_link($this->targetPath()),
            'target' => $this->relativePath,
        ]);
    }

    /**
     * @return WorkflowResult<array{path: string, removed: bool}>
     */
    public function execute(): WorkflowResult
    {
        $target = $this->targetPath();
        $symlinkAncestor = $this->pathGuard->symlinkAncestor($this->root, $this->relativePath);

        if (null !== $symlinkAncestor) {
            return WorkflowResult::blocked([
                Message::create(MessageCode::FILESYSTEM_PARENT_SYMLINK, MessageKey::FILESYSTEM_PARENT_SYMLINK, context: [
                    'path' => $this->relativePath,
                    'parent' => $symlinkAncestor,
                ], level: MessageLevel::Warning),
            ]);
        }

        if (!file_exists($target) && !is_link($target)) {
            return WorkflowResult::success([
                'path' => $this->relativePath,
                'removed' => false,
            ], [
                'path' => $this->relativePath,
                'removed' => false,
            ], [
                Message::debug(MessageCode::FILESYSTEM_PATH_REMOVED, MessageKey::FILESYSTEM_PATH_REMOVED, [
                    '%path%' => $this->relativePath,
                ], [
                    'path' => $this->relativePath,
                    'removed' => false,
                ]),
            ]);
        }

        if (is_link($target)) {
            return WorkflowResult::blocked([
                Message::create(MessageCode::FILESYSTEM_TARGET_SYMLINK, MessageKey::FILESYSTEM_TARGET_SYMLINK, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Warning),
            ]);
        }

        $this->remove($target);

        if (file_exists($target)) {
            return WorkflowResult::failed([
                Message::create(MessageCode::FILESYSTEM_FILE_WRITE_FAILED, MessageKey::FILESYSTEM_FILE_WRITE_FAILED, context: [
                    'path' => $this->relativePath,
                ], level: MessageLevel::Error),
            ]);
        }

        return WorkflowResult::success([
            'path' => $this->relativePath,
            'removed' => true,
        ], [
            'path' => $this->relativePath,
            'removed' => true,
        ], [
            Message::create(MessageCode::FILESYSTEM_PATH_REMOVED, MessageKey::FILESYSTEM_PATH_REMOVED, [
                '%path%' => $this->relativePath,
            ], [
                'path' => $this->relativePath,
                'removed' => true,
            ], MessageLevel::Success),
        ]);
    }

    private function targetPath(): string
    {
        return $this->pathGuard->join($this->root, $this->relativePath);
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            $this->removeFileOrLink($path);
            return;
        }

        $entries = scandir($path);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_link($child) || is_file($child)) {
                $this->removeFileOrLink($child);
                continue;
            }

            if (is_dir($child)) {
                $this->remove($child);
            }
        }

        @rmdir($path);
    }

    private function removeFileOrLink(string $path): void
    {
        if ('\\' === DIRECTORY_SEPARATOR && @rmdir($path)) {
            return;
        }

        @unlink($path);
    }
}
