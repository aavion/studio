<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Filesystem\CopyFileAction;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use InvalidArgumentException;

final readonly class PackageOperationPlanner
{
    public function __construct(
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param list<string> $files
     *
     * @return OperationResult<ActionQueue>
     */
    public function copyFiles(
        PackageCandidate $candidate,
        string $targetRoot,
        array $files,
        string $queueName = 'package copy',
        string $targetPrefix = '',
        bool $overwrite = false,
    ): OperationResult {
        $issues = [];
        $normalizedFiles = $this->normalizedFiles($files);
        $targetPrefix = $this->normalizeOptionalPrefix($targetPrefix);

        foreach ($normalizedFiles as $file) {
            $sourcePath = $this->pathGuard->join($candidate->directory(), $file);

            if (is_link($sourcePath)) {
                $issues[] = OperationIssue::create('package.copy_source_symlink', 'Package file cannot be copied because the source path is a symbolic link.', [
                    'source' => $candidate->source()->name(),
                    'package' => $candidate->directory(),
                    'file' => $file,
                    'path' => $sourcePath,
                ]);
            } elseif (!is_file($sourcePath)) {
                $issues[] = OperationIssue::create('package.copy_source_missing', 'Package file cannot be copied because the source file is missing.', [
                    'source' => $candidate->source()->name(),
                    'package' => $candidate->directory(),
                    'file' => $file,
                    'path' => $sourcePath,
                ]);
            }
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues, [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'target_root' => $targetRoot,
                'target_prefix' => $targetPrefix,
                'files' => $normalizedFiles,
            ]);
        }

        $queue = ActionQueue::create($queueName, context: [
            'source' => $candidate->source()->name(),
            'package' => $candidate->directory(),
            'target_root' => $targetRoot,
            'target_prefix' => $targetPrefix,
            'files' => $normalizedFiles,
        ]);

        foreach ($normalizedFiles as $file) {
            $queue = $queue->add(new CopyFileAction(
                $candidate->directory(),
                $file,
                $targetRoot,
                $this->targetPath($targetPrefix, $file),
                $overwrite,
            ));
        }

        return OperationResult::success($queue, $queue->context());
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function normalizedFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $file) {
            if (!is_string($file)) {
                throw new InvalidArgumentException('Package copy files must contain only strings.');
            }

            $normalized[] = $this->pathGuard->relativePath($file);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    private function normalizeOptionalPrefix(string $targetPrefix): string
    {
        $targetPrefix = trim($targetPrefix);

        if ('' === $targetPrefix) {
            return '';
        }

        return $this->pathGuard->relativePath($targetPrefix);
    }

    private function targetPath(string $targetPrefix, string $file): string
    {
        if ('' === $targetPrefix) {
            return $file;
        }

        return $targetPrefix.'/'.$file;
    }
}
