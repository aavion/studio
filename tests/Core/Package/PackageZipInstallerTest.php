<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Package\Install\PackageZipInstaller;
use App\Core\Workflow\WorkflowStatus;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use ZipArchive;

final class PackageZipInstallerTest extends KernelTestCase
{
    private string $projectDir;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testItVerifiesZipAndReturnsReviewContinuation(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = 'aaaaaaaaaaaaaaaaaaaaaaaa';
        $this->writeUploadZip($installId, 'zip-install-review');

        $result = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::RequiresReview, $result->status());
        self::assertSame('zip-install-review', $result->value()['package']);
        self::assertSame(
            LiveOperationQueueFactory::PACKAGE_INSTALL_APPLY,
            $result->context()['live_operation_continuation']['operation'],
        );

        $this->removePath($this->installRoot($installId));
    }

    public function testItInstallsZipAndRunsDiscovery(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = 'bbbbbbbbbbbbbbbbbbbbbbbb';
        $slug = 'zip-install-apply';
        $this->removePath($this->projectDir.'/packages/'.$slug);
        $this->deletePackageRow($slug);
        $this->writeUploadZip($installId, $slug);

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'package' => $slug,
            'was_active' => false,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertFileExists($this->projectDir.'/packages/'.$slug.'/.manifest');
        self::assertInstanceOf(ExtensionPackage::class, $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $slug,
        ]));

        $this->removePath($this->projectDir.'/packages/'.$slug);
        $this->removePath($this->installRoot($installId));
        $this->deletePackageRow($slug);
    }

    private function installer(): PackageZipInstaller
    {
        $installer = self::getContainer()->get(PackageZipInstaller::class);
        self::assertInstanceOf(PackageZipInstaller::class, $installer);

        return $installer;
    }

    private function writeUploadZip(string $installId, string $slug): void
    {
        $root = $this->installRoot($installId);
        $source = $root.'/source/'.$slug;
        $this->removePath($root);
        mkdir($source, 0775, true);
        file_put_contents($source.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion Test
            PACKAGE_SLUG={$slug}
            PACKAGE_NAME=ZIP Install Test
            PACKAGE_DESCRIPTION=Package ZIP installer test fixture.
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
        file_put_contents($source.'/README.md', "# ZIP Install Test\n");

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($source.'/.manifest', $slug.'/.manifest');
        $zip->addFile($source.'/README.md', $slug.'/README.md');
        $zip->close();
    }

    private function installRoot(string $installId): string
    {
        return $this->projectDir.'/var/cache/test/package-installs/'.$installId;
    }

    private function deletePackageRow(string $slug): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ExtensionPackage::class, 'pkg')
            ->where('pkg.packageName = :package')
            ->setParameter('package', $slug)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
