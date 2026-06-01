<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\Install\PackageZipInstaller;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowStatus;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use ZipArchive;

final class PackageZipInstallerTest extends KernelTestCase
{
    private const TEST_PACKAGE_SLUGS = [
        'zip-install-apply',
        'zip-install-dependent',
        'zip-install-dependent-addon',
        'zip-install-review',
        'zip-install-rollback',
        'zip-install-symlink',
    ];

    private const TEST_INSTALL_IDS = [
        'aaaaaaaaaaaaaaaaaaaaaaaa',
        'bbbbbbbbbbbbbbbbbbbbbbbb',
        'cccccccccccccccccccccccc',
        'dddddddddddddddddddddddd',
        'eeeeeeeeeeeeeeeeeeeeeeee',
        'ffffffffffffffffffffffff',
        '777777777777777777777777',
        '999999999999999999999999',
    ];

    private string $projectDir;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (self::TEST_PACKAGE_SLUGS as $slug) {
            $this->removePath($this->projectDir.'/packages/'.$slug);
            $this->deletePackageRow($slug);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TEST_PACKAGE_SLUGS as $slug) {
            $this->removePath($this->projectDir.'/packages/'.$slug);
            $this->deletePackageRow($slug);
        }

        foreach (self::TEST_INSTALL_IDS as $installId) {
            $this->removePath($this->installRoot($installId));
        }

        parent::tearDown();
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

    public function testItBlocksUnsafeActiveOverwriteBeforeDeactivation(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = 'cccccccccccccccccccccccc';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/packages/'.$slug;
        $this->removePath($target);
        $this->deletePackageRow($slug);
        $this->writePackageDirectory($target, $slug, '1.0.0', 'old package');
        $this->persistPackage($slug, ExtensionPackageStatus::Active);
        $this->writeUploadZip(
            $installId,
            $slug,
            dependencies: '[["missing-package", "1.0.0"]]',
            version: '1.1.0',
            readme: "new package\n",
        );

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Blocked, $verify->status());
        self::assertStringContainsString('old package', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionPackageStatus::Active, $this->packageStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deletePackageRow($slug);
    }

    public function testItBlocksSameVersionOverwriteForInstalledPackages(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = 'eeeeeeeeeeeeeeeeeeeeeeee';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/packages/'.$slug;
        $this->removePath($target);
        $this->deletePackageRow($slug);
        $this->writePackageDirectory($target, $slug, '1.0.0', 'old package');
        $this->persistPackage($slug, ExtensionPackageStatus::Inactive);
        $this->writeUploadZip($installId, $slug, version: '1.0.0', readme: "same version\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Blocked, $verify->status());
        self::assertSame('package.install.version_blocked', $verify->firstIssue()?->code());
        self::assertStringContainsString('old package', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionPackageStatus::Inactive, $this->packageStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deletePackageRow($slug);
    }

    public function testItAllowsSameVersionRecoveryForRemovedPackages(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = '999999999999999999999999';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/packages/'.$slug;
        $this->removePath($target);
        $this->deletePackageRow($slug);
        $this->persistPackage($slug, ExtensionPackageStatus::Removed);
        $this->writeUploadZip($installId, $slug, version: '1.0.0', readme: "same version recovery\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deletePackageRow($slug);
    }

    public function testItBlocksOlderPackageVersionsAgainstRegistry(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = 'ffffffffffffffffffffffff';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/packages/'.$slug;
        $this->removePath($target);
        $this->deletePackageRow($slug);
        $this->writePackageDirectory($target, $slug, '1.1.0', 'old package');
        $this->persistPackage($slug, ExtensionPackageStatus::Removed, version: '1.1.0');
        $this->writeUploadZip($installId, $slug, version: '1.0.0', readme: "older package\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Blocked, $verify->status());
        self::assertSame('package.install.version_blocked', $verify->firstIssue()?->code());
        self::assertStringContainsString('old package', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionPackageStatus::Removed, $this->packageStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deletePackageRow($slug);
    }

    public function testItRejectsSymlinkEntriesBeforeExtraction(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = '777777777777777777777777';
        $slug = 'zip-install-symlink';
        $root = $this->installRoot($installId);
        $this->removePath($root);
        mkdir($root, 0775, true);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($slug.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion Test
            PACKAGE_SLUG={$slug}
            PACKAGE_NAME=ZIP Install Test
            PACKAGE_DESCRIPTION=Package ZIP installer test fixture.
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
        $zip->addFromString($slug.'/assets/host-file.txt', '/etc/passwd');
        self::assertTrue($zip->setExternalAttributesName(
            $slug.'/assets/host-file.txt',
            ZipArchive::OPSYS_UNIX,
            0o120777 << 16,
        ));
        $zip->close();

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Invalid, $verify->status());
        self::assertSame('package.install.zip_invalid', $verify->firstIssue()?->code());
        self::assertSame('symlink_entry', $verify->firstIssue()?->context()['reason'] ?? null);

        $this->removePath($root);
    }

    public function testItRestoresActiveReverseDependentsAfterSuccessfulOverwrite(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for package ZIP installer tests.');
        }

        $installId = 'dddddddddddddddddddddddd';
        $slug = 'zip-install-dependent';
        $dependentSlug = 'zip-install-dependent-addon';
        $target = $this->projectDir.'/packages/'.$slug;
        $this->removePath($target);
        $this->deletePackageRow($slug);
        $this->deletePackageRow($dependentSlug);
        $this->writePackageDirectory($target, $slug, '1.0.0', 'old package');
        $this->writePackageDirectory(
            $this->projectDir.'/packages/'.$dependentSlug,
            $dependentSlug,
            '1.0.0',
            'dependent package',
            sprintf('[["%s", "1.0.0"]]', $slug),
        );
        $this->persistPackage($slug, ExtensionPackageStatus::Active);
        $this->persistPackage(
            $dependentSlug,
            ExtensionPackageStatus::Active,
            dependencies: sprintf('[["%s", "1.0.0"]]', $slug),
        );
        $this->writeUploadZip($installId, $slug, version: '1.1.0', readme: "new package\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'package' => $slug,
            'was_active' => true,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringContainsString('new package', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionPackageStatus::Active, $this->packageStatus($slug));
        self::assertSame(ExtensionPackageStatus::Active, $this->packageStatus($dependentSlug));
        self::assertSame('1.1.0', $this->packageVersion($slug));

        $this->removePath($target);
        $this->removePath($this->projectDir.'/packages/'.$dependentSlug);
        $this->removePath($this->installRoot($installId));
        $this->deletePackageRow($slug);
        $this->deletePackageRow($dependentSlug);
    }

    private function installer(): PackageZipInstaller
    {
        $installer = self::getContainer()->get(PackageZipInstaller::class);
        self::assertInstanceOf(PackageZipInstaller::class, $installer);

        return $installer;
    }

    private function writeUploadZip(
        string $installId,
        string $slug,
        string $dependencies = '[]',
        string $version = '1.0.0',
        string $readme = "# ZIP Install Test\n",
    ): void {
        $root = $this->installRoot($installId);
        $source = $root.'/source/'.$slug;
        $this->removePath($root);
        mkdir($source, 0775, true);
        file_put_contents($source.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion Test
            PACKAGE_SLUG={$slug}
            PACKAGE_NAME=ZIP Install Test
            PACKAGE_DESCRIPTION=Package ZIP installer test fixture.
            PACKAGE_VERSION={$version}
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES={$dependencies}
            MANIFEST);
        file_put_contents($source.'/README.md', $readme);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($source.'/.manifest', $slug.'/.manifest');
        $zip->addFile($source.'/README.md', $slug.'/README.md');
        $zip->close();
    }

    private function writePackageDirectory(
        string $target,
        string $slug,
        string $version,
        string $readme,
        string $dependencies = '[]',
    ): void
    {
        mkdir($target, 0775, true);
        file_put_contents($target.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion Test
            PACKAGE_SLUG={$slug}
            PACKAGE_NAME=ZIP Install Test
            PACKAGE_DESCRIPTION=Package ZIP installer test fixture.
            PACKAGE_VERSION={$version}
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES={$dependencies}
            MANIFEST);
        file_put_contents($target.'/README.md', $readme);
    }

    private function installRoot(string $installId): string
    {
        return $this->projectDir.'/var/cache/test/package-installs/'.$installId;
    }

    private function persistPackage(
        string $slug,
        ExtensionPackageStatus $status,
        string $dependencies = '[]',
        string $version = '1.0.0',
    ): void
    {
        $this->entityManager->persist(new ExtensionPackage(
            $this->uuid(),
            [PackageScope::Module],
            $slug,
            'packages/'.$slug,
            $status,
            [
                'registry_state' => 'available',
                'manifest' => [
                    'PACKAGE_DEPENDENCIES' => $dependencies,
                ],
            ],
            manifestVersion: $version,
            installedVersion: $version,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function packageStatus(string $slug): ExtensionPackageStatus
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $slug,
        ]);

        self::assertInstanceOf(ExtensionPackage::class, $package);

        return $package->status();
    }

    private function packageVersion(string $slug): ?string
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $slug,
        ]);

        self::assertInstanceOf(ExtensionPackage::class, $package);

        return $package->installedVersion();
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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

            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
