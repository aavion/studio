<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Manifest\Manifest;
use App\Core\Operation\OperationExecutor;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageOperationPlanner;
use App\Core\Package\PackageSource;
use App\Core\Workflow\OperationStatus;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PackageOperationPlannerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $packageDir;

    private string $targetDir;

    protected function setUp(): void
    {
        $this->packageDir = $this->createTemporaryDirectory('studio-package-planner-source');
        $this->targetDir = $this->createTemporaryDirectory('studio-package-planner-target');
        $this->writePackageFile('.manifest', 'PACKAGE_NAME=Demo');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->packageDir);
        $this->removeDirectory($this->targetDir);
    }

    public function testItCreatesDeterministicCopyQueue(): void
    {
        $this->writePackageFile('templates/base.html.twig', '<main></main>');
        $this->writePackageFile('assets/app.css', 'body {}');

        $result = (new PackageOperationPlanner())->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['templates/base.html.twig', 'assets/app.css', 'assets/app.css'],
            'import package files',
            'packages/system',
        );

        self::assertTrue($result->isSuccess());
        self::assertSame([
            'assets/app.css',
            'templates/base.html.twig',
        ], $result->value()->context()['files']);
        self::assertSame('packages/system', $result->value()->context()['target_prefix']);
        self::assertCount(2, $result->value());

        $plan = (new OperationExecutor())->planQueue($result->value());

        self::assertSame('import package files', $plan->name());
        self::assertSame(['copy_file' => 2], $plan->actionCounts());
        self::assertSame([
            'assets/app.css',
            'packages/system/assets/app.css',
        ], $plan->actions()[0]->paths());
    }

    public function testPlannedQueueCanCopyPackageFilesToTargetRoot(): void
    {
        $this->writePackageFile('assets/app.css', 'body { color: red; }');

        $queue = (new PackageOperationPlanner())->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['assets/app.css'],
            targetPrefix: 'packages/system',
        )->value();

        $execution = (new OperationExecutor())->executeQueue($queue);

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame('body { color: red; }', file_get_contents($this->targetDir.'/packages/system/assets/app.css'));
    }

    public function testItReportsMissingSourceFilesBeforeCreatingQueue(): void
    {
        $result = (new PackageOperationPlanner())->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['missing.txt'],
        );

        self::assertSame(OperationStatus::Invalid, $result->status());
        self::assertSame('package.copy_source_missing', $result->firstIssue()?->code());
        self::assertSame(['missing.txt'], $result->context()['files']);
    }

    public function testItReportsSymbolicSourceFilesBeforeCreatingQueue(): void
    {
        $this->writePackageFile('real.txt', 'real');
        $this->createSymlinkOrSkip($this->packageDir.'/real.txt', $this->packageDir.'/linked.txt');

        $result = (new PackageOperationPlanner())->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['linked.txt'],
        );

        self::assertSame(OperationStatus::Invalid, $result->status());
        self::assertSame('package.copy_source_symlink', $result->firstIssue()?->code());
    }

    public function testItRejectsUnsafePackageFilePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PackageOperationPlanner())->copyFiles(
            $this->candidate(),
            $this->targetDir,
            ['../outside.txt'],
        );
    }

    private function candidate(): PackageCandidate
    {
        return new PackageCandidate(
            PackageSource::children('import', 'imports'),
            $this->packageDir,
            $this->packageDir.'/.manifest',
            new Manifest(['PACKAGE_NAME' => 'Demo']),
        );
    }

    private function writePackageFile(string $relativePath, string $contents): void
    {
        $this->writeTestFile($this->packageDir, $relativePath, $contents);
    }
}
