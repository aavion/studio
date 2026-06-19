<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Manifest\Manifest;
use App\Core\Operation\OperationExecutor;
use App\Core\Extension\ExtensionCandidate;
use App\Core\Extension\ExtensionOperationPlanner;
use App\Core\Extension\ExtensionSource;
use App\Core\Workflow\WorkflowStatus;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PHPUnit\Framework\TestCase;

final class ExtensionOperationPlannerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $extensionDir;

    private string $targetDir;

    protected function setUp(): void
    {
        $this->extensionDir = $this->createTemporaryDirectory('system-extension-planner-source');
        $this->targetDir = $this->createTemporaryDirectory('system-extension-planner-target');
        $this->writeExtensionFile('.manifest', 'EXTENSION_NAME=Demo');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->extensionDir);
        $this->removeDirectory($this->targetDir);
    }

    public function testItCreatesDeterministicCopyQueue(): void
    {
        $this->writeExtensionFile('templates/base.html.twig', '<main></main>');
        $this->writeExtensionFile('assets/app.css', 'body {}');

        $result = (new ExtensionOperationPlanner(new NullWorkflowResultMessageReporter()))->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['templates/base.html.twig', 'assets/app.css', 'assets/app.css'],
            'import extension files',
            'extensions/system',
        );

        self::assertTrue($result->isSuccess());
        self::assertSame([
            'assets/app.css',
            'templates/base.html.twig',
        ], $result->value()->context()['files']);
        self::assertSame('extensions/system', $result->value()->context()['target_prefix']);
        self::assertCount(2, $result->value());

        $plan = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->planQueue($result->value());

        self::assertSame('import extension files', $plan->name());
        self::assertSame(['copy_file' => 2], $plan->actionCounts());
        self::assertSame([
            'assets/app.css',
            'extensions/system/assets/app.css',
        ], $plan->actions()[0]->paths());
    }

    public function testPlannedQueueCanCopyExtensionFilesToTargetRoot(): void
    {
        $this->writeExtensionFile('assets/app.css', 'body { color: red; }');

        $queue = (new ExtensionOperationPlanner(new NullWorkflowResultMessageReporter()))->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['assets/app.css'],
            targetPrefix: 'extensions/system',
        )->value();

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue($queue);

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame('body { color: red; }', file_get_contents($this->targetDir.'/extensions/system/assets/app.css'));
    }

    public function testItReportsMissingSourceFilesBeforeCreatingQueue(): void
    {
        $result = (new ExtensionOperationPlanner(new NullWorkflowResultMessageReporter()))->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['missing.txt'],
        );

        self::assertSame(WorkflowStatus::Invalid, $result->status());
        self::assertSame('extension.copy_source_missing', $result->firstIssue()?->code());
        self::assertSame(['missing.txt'], $result->context()['files']);
    }

    public function testItReportsSymbolicSourceFilesBeforeCreatingQueue(): void
    {
        $this->writeExtensionFile('real.txt', 'real');
        $this->createSymlinkOrSkip($this->extensionDir.'/real.txt', $this->extensionDir.'/linked.txt');

        $result = (new ExtensionOperationPlanner(new NullWorkflowResultMessageReporter()))->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['linked.txt'],
        );

        self::assertSame(WorkflowStatus::Invalid, $result->status());
        self::assertSame('extension.copy_source_symlink', $result->firstIssue()?->code());
    }

    public function testItRejectsUnsafeExtensionFilePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExtensionOperationPlanner(new NullWorkflowResultMessageReporter()))->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['../outside.txt'],
        );
    }

    private function candidate(): ExtensionCandidate
    {
        return new ExtensionCandidate(
            ExtensionSource::children('import', 'imports'),
            $this->extensionDir,
            $this->extensionDir.'/.manifest',
            new Manifest(['EXTENSION_NAME' => 'Demo']),
        );
    }

    private function writeExtensionFile(string $relativePath, string $contents): void
    {
        $this->writeTestFile($this->extensionDir, $relativePath, $contents);
    }
}
