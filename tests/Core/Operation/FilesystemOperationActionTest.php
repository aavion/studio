<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Filesystem\CopyFileAction;
use App\Core\Operation\Filesystem\EnsureDirectoryAction;
use App\Core\Operation\Filesystem\WriteFileAction;
use App\Core\Operation\OperationExecutor;
use App\Core\Workflow\OperationStatus;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FilesystemOperationActionTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-operation-actions');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEnsureDirectoryCreatesMissingDirectory(): void
    {
        $action = new EnsureDirectoryAction($this->root, 'var/cache/imports');
        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('ensure directories', [$action]));

        self::assertTrue($execution->result()->isSuccess());
        self::assertDirectoryExists($this->root.'/var/cache/imports');
        self::assertTrue($execution->actionLog()->entries()[0]->context()['created']);
        self::assertSame(['var/cache/imports'], $action->dryRun()->paths());
    }

    public function testEnsureDirectoryBlocksWhenFileExistsAtTarget(): void
    {
        $this->writeTestFile($this->root, 'cache', 'not a directory');

        $result = (new EnsureDirectoryAction($this->root, 'cache'))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.directory_conflict', $result->firstIssue()?->code());
    }

    public function testWriteFileCreatesParentDirectoriesAndReportsDryRunDiff(): void
    {
        $action = new WriteFileAction($this->root, 'config/generated.php', '<?php return [];');
        $dryRun = $action->dryRun();

        self::assertSame('write_file', $dryRun->type());
        self::assertSame(DryRunRisk::Low, $dryRun->risk());
        self::assertSame(['config/generated.php'], $dryRun->paths());
        self::assertTrue($dryRun->hasDiffs());

        $result = $action->execute();

        self::assertTrue($result->isSuccess());
        self::assertSame('<?php return [];', file_get_contents($this->root.'/config/generated.php'));
        self::assertSame(strlen('<?php return [];'), $result->value()['bytes']);
        self::assertFalse($result->value()['overwritten']);
    }

    public function testWriteFileBlocksExistingFileWithoutOverwrite(): void
    {
        $this->writeTestFile($this->root, 'config.php', 'old');

        $result = (new WriteFileAction($this->root, 'config.php', 'new'))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.file_exists', $result->firstIssue()?->code());
        self::assertSame('old', file_get_contents($this->root.'/config.php'));
    }

    public function testWriteFileOverwritesExistingFileWhenAllowed(): void
    {
        $this->writeTestFile($this->root, 'config.php', 'old');

        $action = new WriteFileAction($this->root, 'config.php', 'new', overwrite: true);

        self::assertSame(DryRunRisk::Medium, $action->dryRun()->risk());

        $result = $action->execute();

        self::assertTrue($result->isSuccess());
        self::assertSame('new', file_get_contents($this->root.'/config.php'));
        self::assertTrue($result->value()['overwritten']);
    }

    public function testWriteFileBlocksSymbolicTargets(): void
    {
        $this->writeTestFile($this->root, 'real.txt', 'real');

        if (!@symlink($this->root.'/real.txt', $this->root.'/linked.txt')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $result = (new WriteFileAction($this->root, 'linked.txt', 'new', overwrite: true))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.target_symlink', $result->firstIssue()?->code());
        self::assertSame('real', file_get_contents($this->root.'/real.txt'));
    }

    public function testWriteFileDryRunDoesNotReadSymbolicTargets(): void
    {
        $this->writeTestFile($this->root, 'real.txt', 'real');

        if (!@symlink($this->root.'/real.txt', $this->root.'/linked.txt')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $dryRun = (new WriteFileAction($this->root, 'linked.txt', 'new', overwrite: true))->dryRun();

        self::assertTrue($dryRun->context()['target_is_symlink']);
        self::assertFalse($dryRun->context()['exists']);
        self::assertSame('', $dryRun->diffs()[0]->payload()['before']);
    }

    public function testCopyFileCreatesParentDirectories(): void
    {
        $this->writeTestFile($this->root, 'source.txt', 'payload');

        $action = new CopyFileAction($this->root, 'source.txt', $this->root, 'nested/target.txt');
        $result = $action->execute();

        self::assertTrue($result->isSuccess());
        self::assertSame('payload', file_get_contents($this->root.'/nested/target.txt'));
        self::assertSame(7, $result->value()['bytes']);
        self::assertSame(['source.txt', 'nested/target.txt'], $action->dryRun()->paths());
    }

    public function testCopyFileBlocksMissingSource(): void
    {
        $result = (new CopyFileAction($this->root, 'missing.txt', $this->root, 'target.txt'))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.source_missing', $result->firstIssue()?->code());
    }

    public function testCopyFileBlocksExistingTargetWithoutOverwrite(): void
    {
        $this->writeTestFile($this->root, 'source.txt', 'new');
        $this->writeTestFile($this->root, 'target.txt', 'old');

        $result = (new CopyFileAction($this->root, 'source.txt', $this->root, 'target.txt'))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.file_exists', $result->firstIssue()?->code());
        self::assertSame('old', file_get_contents($this->root.'/target.txt'));
    }

    public function testCopyFileBlocksSymbolicSources(): void
    {
        $this->writeTestFile($this->root, 'real.txt', 'real');

        if (!@symlink($this->root.'/real.txt', $this->root.'/linked.txt')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $result = (new CopyFileAction($this->root, 'linked.txt', $this->root, 'target.txt'))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.source_symlink', $result->firstIssue()?->code());
    }

    public function testCopyFileBlocksSymbolicTargets(): void
    {
        $this->writeTestFile($this->root, 'source.txt', 'source');
        $this->writeTestFile($this->root, 'real.txt', 'real');

        if (!@symlink($this->root.'/real.txt', $this->root.'/linked.txt')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $result = (new CopyFileAction($this->root, 'source.txt', $this->root, 'linked.txt', overwrite: true))->execute();

        self::assertSame(OperationStatus::Blocked, $result->status());
        self::assertSame('filesystem.target_symlink', $result->firstIssue()?->code());
        self::assertSame('real', file_get_contents($this->root.'/real.txt'));
    }

    public function testCopyFileDryRunDoesNotReadSymbolicSourcesOrTargets(): void
    {
        $this->writeTestFile($this->root, 'source-real.txt', 'source');
        $this->writeTestFile($this->root, 'target-real.txt', 'target');

        if (!@symlink($this->root.'/source-real.txt', $this->root.'/source-link.txt')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        if (!@symlink($this->root.'/target-real.txt', $this->root.'/target-link.txt')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $dryRun = (new CopyFileAction($this->root, 'source-link.txt', $this->root, 'target-link.txt', overwrite: true))->dryRun();

        self::assertTrue($dryRun->context()['source_is_symlink']);
        self::assertTrue($dryRun->context()['target_is_symlink']);
        self::assertFalse($dryRun->context()['source_exists']);
        self::assertFalse($dryRun->context()['target_exists']);
        self::assertSame('', $dryRun->diffs()[0]->payload()['before']);
        self::assertSame('', $dryRun->diffs()[0]->payload()['after']);
    }

    public function testFilesystemActionsRejectTraversalPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WriteFileAction($this->root, '../outside.txt', 'payload');
    }

}
