<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\Install\ExtensionZipInstaller;
use App\Core\Extension\ExtensionScope;
use App\Core\Workflow\WorkflowStatus;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use ZipArchive;

final class ExtensionZipInstallerTest extends KernelTestCase
{
    private const TEST_EXTENSION_SLUGS = [
        'zip-install-apply',
        'zip-install-dependent',
        'zip-install-dependent-addon',
        'zip-install-review',
        'zip-install-rollback',
        'zip-install-symlink',
        'zip-install-deep-policy',
        'zip-install-skip',
    ];

    private const TEST_EXTENSION_DATABASE_SLUGS = [
        ...self::TEST_EXTENSION_SLUGS,
        'demo-captcha-provider',
        'demo-frontend-theme',
        'demo-module',
    ];

    private const TEST_INSTALL_IDS = [
        'aaaaaaaaaaaaaaaaaaaaaaaa',
        'bbbbbbbbbbbbbbbbbbbbbbbb',
        'cccccccccccccccccccccccc',
        'dddddddddddddddddddddddd',
        'eeeeeeeeeeeeeeeeeeeeeeee',
        'ffffffffffffffffffffffff',
        '777777777777777777777777',
        '888888888888888888888888',
        '999999999999999999999999',
        '121212121212121212121212',
    ];

    private string $projectDir;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (self::TEST_EXTENSION_SLUGS as $slug) {
            $this->removePath($this->projectDir.'/extensions/'.$slug);
        }

        foreach (self::TEST_EXTENSION_DATABASE_SLUGS as $slug) {
            $this->deleteExtensionRow($slug);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TEST_EXTENSION_SLUGS as $slug) {
            $this->removePath($this->projectDir.'/extensions/'.$slug);
        }

        foreach (self::TEST_EXTENSION_DATABASE_SLUGS as $slug) {
            $this->deleteExtensionRow($slug);
        }

        foreach (self::TEST_INSTALL_IDS as $installId) {
            $this->removePath($this->installRoot($installId));
        }

        parent::tearDown();
    }

    public function testItVerifiesZipAndReturnsReviewContinuation(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = 'aaaaaaaaaaaaaaaaaaaaaaaa';
        $this->writeUploadZip($installId, 'zip-install-review');

        $result = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::RequiresReview, $result->status());
        self::assertSame('zip-install-review', $result->value()['extension']);
        self::assertSame(
            LiveOperationQueueFactory::EXTENSION_INSTALL_APPLY,
            $result->context()['live_operation_continuation']['operation'],
        );

        $this->removePath($this->installRoot($installId));
    }

    public function testItInstallsZipAndRunsDiscovery(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = 'bbbbbbbbbbbbbbbbbbbbbbbb';
        $slug = 'zip-install-apply';
        $this->removePath($this->projectDir.'/extensions/'.$slug);
        $this->deleteExtensionRow($slug);
        $this->writeUploadZip($installId, $slug);

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => false,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertFileExists($this->projectDir.'/extensions/'.$slug.'/.manifest');
        self::assertInstanceOf(Extension::class, $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $slug,
        ]));

        $this->removePath($this->projectDir.'/extensions/'.$slug);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    public function testItBlocksUnsafeActiveOverwriteBeforeDeactivation(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = 'cccccccccccccccccccccccc';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->writeExtensionDirectory($target, $slug, '1.0.0', 'old extension');
        $this->persistExtension($slug, ExtensionStatus::Active);
        $this->writeUploadZip(
            $installId,
            $slug,
            dependencies: '[["missing-extension", "1.0.0"]]',
            version: '1.1.0',
            readme: "new extension\n",
        );

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Blocked, $verify->status());
        self::assertStringContainsString('old extension', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionStatus::Active, $this->extensionStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    public function testItBlocksSameVersionOverwriteForInstalledExtensions(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = 'eeeeeeeeeeeeeeeeeeeeeeee';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->writeExtensionDirectory($target, $slug, '1.0.0', 'old extension');
        $this->persistExtension($slug, ExtensionStatus::Inactive);
        $this->writeUploadZip($installId, $slug, version: '1.0.0', readme: "same version\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Blocked, $verify->status());
        self::assertSame('extension.install.version_blocked', $verify->firstIssue()?->code());
        self::assertStringContainsString('old extension', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionStatus::Inactive, $this->extensionStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    public function testItAllowsSameVersionRecoveryForRemovedExtensions(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '999999999999999999999999';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->persistExtension($slug, ExtensionStatus::Removed);
        $this->writeUploadZip($installId, $slug, version: '1.0.0', readme: "same version recovery\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    public function testItBlocksOlderExtensionVersionsAgainstRegistry(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = 'ffffffffffffffffffffffff';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->writeExtensionDirectory($target, $slug, '1.1.0', 'old extension');
        $this->persistExtension($slug, ExtensionStatus::Removed, version: '1.1.0');
        $this->writeUploadZip($installId, $slug, version: '1.0.0', readme: "older extension\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Blocked, $verify->status());
        self::assertSame('extension.install.version_blocked', $verify->firstIssue()?->code());
        self::assertStringContainsString('old extension', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionStatus::Removed, $this->extensionStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    public function testItRejectsSymlinkEntriesBeforeExtraction(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '777777777777777777777777';
        $slug = 'zip-install-symlink';
        $root = $this->installRoot($installId);
        $this->removePath($root);
        mkdir($root, 0775, true);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($slug.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion Test
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=ZIP Install Test
            EXTENSION_DESCRIPTION=Extension ZIP installer test fixture.
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES=[]
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
        self::assertSame('extension.install.zip_invalid', $verify->firstIssue()?->code());
        self::assertSame('symlink_entry', $verify->firstIssue()?->context()['reason'] ?? null);

        $this->removePath($root);
    }

    public function testItRejectsPolicyBlockedExtensionPaths(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '888888888888888888888888';
        $slug = 'zip-install-symlink';
        $root = $this->installRoot($installId);
        $this->removePath($root);
        mkdir($root, 0775, true);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($slug.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion Test
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=ZIP Install Test
            EXTENSION_DESCRIPTION=Extension ZIP installer test fixture.
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
        $zip->addFromString($slug.'/public/index.php', '<?php echo "blocked";');
        $zip->close();

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Invalid, $verify->status());
        self::assertSame('extension.policy.blocked_path', $verify->firstIssue()?->code());
        self::assertSame('reserved_project_path', $verify->firstIssue()?->context()['reason']);

        $this->removePath($root);
    }

    public function testItRejectsDeepPolicyBlockedExtensionPaths(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '999999999999999999999999';
        $slug = 'zip-install-deep-policy';
        $root = $this->installRoot($installId);
        $this->removePath($root);
        mkdir($root, 0775, true);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($slug.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion Test
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=ZIP Install Test
            EXTENSION_DESCRIPTION=Extension ZIP installer test fixture.
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
        $zip->addFromString($slug.'/assets/a/b/c/d/shell.php', '<?php echo "blocked";');
        $zip->close();

        $verify = $this->installer()->verify(['install_id' => $installId]);

        self::assertSame(WorkflowStatus::Invalid, $verify->status());
        self::assertSame('extension.policy.blocked_path', $verify->firstIssue()?->code());
        self::assertSame('asset_executable_file', $verify->firstIssue()?->context()['reason']);

        $this->removePath($root);
    }

    public function testItSkipsDevelopmentArtifactsWhenApplyingZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '121212121212121212121212';
        $slug = 'zip-install-skip';
        $target = $this->projectDir.'/extensions/'.$slug;
        $root = $this->installRoot($installId);
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->removePath($root);
        mkdir($root, 0775, true);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($slug.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion Test
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=ZIP Install Test
            EXTENSION_DESCRIPTION=Extension ZIP installer test fixture.
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
        $zip->addFromString($slug.'/README.md', "# ZIP Install Test\n");
        $zip->addFromString($slug.'/docs/readme.md', "Extension docs\n");
        $zip->addFromString($slug.'/.editorconfig', "root = true\n");
        $zip->addFromString($slug.'/.git/config', "[core]\n");
        $zip->addFromString($slug.'/tests/BrokenTest.php', '<?php class BrokenTest {');
        $zip->close();

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status(), json_encode($verify->toArray(), JSON_THROW_ON_ERROR));

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => false,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertFileExists($target.'/.manifest');
        self::assertFileExists($target.'/docs/readme.md');
        self::assertFileDoesNotExist($target.'/.editorconfig');
        self::assertFileDoesNotExist($target.'/.git/config');
        self::assertFileDoesNotExist($target.'/tests/BrokenTest.php');

        $this->removePath($target);
        $this->removePath($root);
        $this->deleteExtensionRow($slug);
    }

    public function testItRestoresActiveReverseDependentsAfterSuccessfulOverwrite(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = 'dddddddddddddddddddddddd';
        $slug = 'zip-install-dependent';
        $dependentSlug = 'zip-install-dependent-addon';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->deleteExtensionRow($dependentSlug);
        $this->writeExtensionDirectory($target, $slug, '1.0.0', 'old extension');
        $this->writeExtensionDirectory(
            $this->projectDir.'/extensions/'.$dependentSlug,
            $dependentSlug,
            '1.0.0',
            'dependent extension',
            sprintf('[["%s", "1.0"]]', $slug),
        );
        $this->persistExtension($slug, ExtensionStatus::Active);
        $this->persistExtension(
            $dependentSlug,
            ExtensionStatus::Active,
            dependencies: sprintf('[["%s", "1.0"]]', $slug),
        );
        $this->writeUploadZip($installId, $slug, version: '1.1.0', readme: "new extension\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => true,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringContainsString('new extension', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionStatus::Active, $this->extensionStatus($slug));
        self::assertSame(ExtensionStatus::Active, $this->extensionStatus($dependentSlug));
        self::assertSame('1.1.0', $this->extensionVersion($slug));

        $this->removePath($target);
        $this->removePath($this->projectDir.'/extensions/'.$dependentSlug);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
        $this->deleteExtensionRow($dependentSlug);
    }

    private function installer(): ExtensionZipInstaller
    {
        $installer = self::getContainer()->get(ExtensionZipInstaller::class);
        self::assertInstanceOf(ExtensionZipInstaller::class, $installer);

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
            EXTENSION_AUTHOR=Aavion Test
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=ZIP Install Test
            EXTENSION_DESCRIPTION=Extension ZIP installer test fixture.
            EXTENSION_VERSION={$version}
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES={$dependencies}
            MANIFEST);
        file_put_contents($source.'/README.md', $readme);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($source.'/.manifest', $slug.'/.manifest');
        $zip->addFile($source.'/README.md', $slug.'/README.md');
        $zip->close();
    }

    private function writeExtensionDirectory(
        string $target,
        string $slug,
        string $version,
        string $readme,
        string $dependencies = '[]',
    ): void
    {
        mkdir($target, 0775, true);
        file_put_contents($target.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion Test
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=ZIP Install Test
            EXTENSION_DESCRIPTION=Extension ZIP installer test fixture.
            EXTENSION_VERSION={$version}
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES={$dependencies}
            MANIFEST);
        file_put_contents($target.'/README.md', $readme);
    }

    private function installRoot(string $installId): string
    {
        return $this->projectDir.'/var/cache/test/extension-installs/'.$installId;
    }

    private function persistExtension(
        string $slug,
        ExtensionStatus $status,
        string $dependencies = '[]',
        string $version = '1.0.0',
    ): void
    {
        $this->entityManager->persist(new Extension(
            $this->uuid(),
            [ExtensionScope::Module],
            $slug,
            'extensions/'.$slug,
            $status,
            [
                'registry_state' => 'available',
                'manifest' => [
                    'EXTENSION_DEPENDENCIES' => $dependencies,
                ],
            ],
            manifestVersion: $version,
            installedVersion: $version,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function extensionStatus(string $slug): ExtensionStatus
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $slug,
        ]);

        self::assertInstanceOf(Extension::class, $extension);

        return $extension->status();
    }

    private function extensionVersion(string $slug): ?string
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $slug,
        ]);

        self::assertInstanceOf(Extension::class, $extension);

        return $extension->installedVersion();
    }

    private function deleteExtensionRow(string $slug): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(Extension::class, 'ext')
            ->where('ext.extensionName = :extension')
            ->setParameter('extension', $slug)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
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
