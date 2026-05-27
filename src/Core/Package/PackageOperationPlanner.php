<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Filesystem\CopyFileAction;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use InvalidArgumentException;

final readonly class PackageOperationPlanner
{
    public function __construct(
        private WorkflowResultMessageReporterInterface $messageReporter,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param list<string> $files
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function copyFiles(
        PackageCandidate $candidate,
        string $targetRoot,
        array $files,
        string $queueName = 'package copy',
        string $targetPrefix = '',
        bool $overwrite = false,
    ): WorkflowResult {
        $issues = [];
        $normalizedFiles = $this->normalizedFiles($files);
        $targetPrefix = $this->normalizeOptionalPrefix($targetPrefix);

        foreach ($normalizedFiles as $file) {
            $sourcePath = $this->pathGuard->join($candidate->directory(), $file);

            if (is_link($sourcePath)) {
                $issues[] = Message::create(MessageCode::PACKAGE_COPY_SOURCE_SYMLINK, MessageKey::PACKAGE_COPY_SOURCE_SYMLINK, [
                    '%path%' => $sourcePath,
                ], [
                    'source' => $candidate->source()->name(),
                    'package' => $candidate->directory(),
                    'file' => $file,
                    'path' => $sourcePath,
                ], MessageLevel::Error);
            } elseif (!is_file($sourcePath)) {
                $issues[] = Message::create(MessageCode::PACKAGE_COPY_SOURCE_MISSING, MessageKey::PACKAGE_COPY_SOURCE_MISSING, [
                    '%path%' => $sourcePath,
                ], [
                    'source' => $candidate->source()->name(),
                    'package' => $candidate->directory(),
                    'file' => $file,
                    'path' => $sourcePath,
                ], MessageLevel::Error);
            }
        }

        if ([] !== $issues) {
            return $this->report(WorkflowResult::invalid($issues, [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'target_root' => $targetRoot,
                'target_prefix' => $targetPrefix,
                'files' => $normalizedFiles,
            ]), $candidate);
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

        return $this->report(WorkflowResult::success($queue, $queue->context(), [
            Message::create(MessageCode::PACKAGE_COPY_PLAN_CREATED, MessageKey::PACKAGE_COPY_PLAN_CREATED, [
                '%count%' => count($normalizedFiles),
            ], $queue->context(), MessageLevel::Success),
        ]), $candidate);
    }

    private function report(WorkflowResult $result, PackageCandidate $candidate): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            'operation' => 'package.copy_plan',
            'source' => $candidate->source()->name(),
            'package' => $candidate->directory(),
        ]);
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
