<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Content\ContentStatus;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaSynchronizer;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionDiscoveryRunner;
use App\Core\Extension\ExtensionLifecycleAssetRebuilderInterface;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\Install\ExtensionInstallApplier;
use App\Core\Extension\Install\ExtensionInstallFilesystem;
use App\Core\Extension\Install\ExtensionInstallPayload;
use App\Core\Extension\Install\ExtensionInstallRegistry;
use App\Core\Extension\Install\ExtensionInstallRollbacker;
use App\Core\Extension\Install\ExtensionInstallStageReader;
use App\Core\Extension\Install\ExtensionInstallVersionGuard;
use App\Core\Extension\Install\ExtensionReactivationPlanner;
use App\Core\Extension\Install\ExtensionReplacementPreflight;
use App\Core\Extension\Install\ExtensionZipInstaller;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Workflow\WorkflowResult;
use App\Core\Workflow\WorkflowStatus;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
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
        'zip-install-content',
        'zip-install-flat',
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
        '131313131313131313131313',
        '141414141414141414141414',
        '151515151515151515151515',
        '161616161616161616161616',
        '171717171717171717171717',
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

        $this->deleteContentFixture();

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

        $this->deleteContentFixture();

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

    public function testItInstallsFlatRootZipIntoManifestSlugDirectory(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '171717171717171717171717';
        $slug = 'zip-install-flat';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->writeUploadZip($installId, $slug, flatRoot: true);

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status(), json_encode($verify->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame($slug, $verify->value()['extension']);

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => false,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertFileExists($target.'/.manifest');
        self::assertFileExists($target.'/README.md');
        self::assertSame(ExtensionStatus::Inactive, $this->extensionStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    public function testItRejectsApplyPayloadWithInvalidInstallId(): void
    {
        $apply = $this->installer()->apply([
            'install_id' => '../outside',
            'extension' => 'zip-install-apply',
        ]);

        self::assertSame(WorkflowStatus::Invalid, $apply->status());
        self::assertSame('E_INVALID_ARGUMENT', $apply->firstIssue()?->code());
    }

    public function testItRejectsApplyPayloadWhenExtensionDoesNotMatchManifest(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '161616161616161616161616';
        $manifestSlug = 'zip-install-review';
        $payloadSlug = 'zip-install-apply';
        $this->removePath($this->projectDir.'/extensions/'.$manifestSlug);
        $this->removePath($this->projectDir.'/extensions/'.$payloadSlug);
        $this->deleteExtensionRow($manifestSlug);
        $this->deleteExtensionRow($payloadSlug);
        $this->writeUploadZip($installId, $manifestSlug);

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $payloadSlug,
            'was_active' => false,
        ]);

        self::assertSame(WorkflowStatus::Invalid, $apply->status());
        self::assertSame('extension.install.zip_invalid', $apply->firstIssue()?->code());
        self::assertSame('extension_mismatch', $apply->firstIssue()?->context()['reason'] ?? null);
        self::assertFileDoesNotExist($this->projectDir.'/extensions/'.$manifestSlug);
        self::assertFileDoesNotExist($this->projectDir.'/extensions/'.$payloadSlug);

        $this->removePath($this->installRoot($installId));
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
        $this->persistExtension($slug, ExtensionStatus::Active, scopes: [ExtensionScope::Module, ExtensionScope::ContentSchema]);
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

    public function testItDeactivatesStaleActiveReverseDependentsWhenOverwritingInactiveExtension(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '141414141414141414141414';
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
        $this->persistExtension($slug, ExtensionStatus::Inactive);
        $this->persistExtension(
            $dependentSlug,
            ExtensionStatus::Active,
            dependencies: sprintf('[["%s", "1.0"]]', $slug),
        );
        $this->writeUploadZip($installId, $slug, version: '1.1.0', readme: "new extension\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());
        self::assertContains($dependentSlug, $verify->value()['deactivate']);

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => false,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringContainsString('new extension', (string) file_get_contents($target.'/README.md'));
        self::assertSame(ExtensionStatus::Inactive, $this->extensionStatus($slug));
        self::assertSame(ExtensionStatus::Inactive, $this->extensionStatus($dependentSlug));
        self::assertSame('1.1.0', $this->extensionVersion($slug));

        $this->removePath($target);
        $this->removePath($this->projectDir.'/extensions/'.$dependentSlug);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
        $this->deleteExtensionRow($dependentSlug);
    }

    public function testItRestoresArchivedContentAfterSuccessfulActiveOverwrite(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '131313131313131313131313';
        $slug = 'zip-install-content';
        $target = $this->projectDir.'/extensions/'.$slug;
        $this->removePath($target);
        $this->deleteContentFixture();
        $this->deleteExtensionRow($slug);
        $this->writeExtensionDirectory($target, $slug, '1.0.0', 'old extension');
        $this->persistExtension($slug, ExtensionStatus::Active);
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy(['extensionName' => $slug]);
        self::assertInstanceOf(Extension::class, $extension);
        $this->createPublishedExtensionContent($extension);
        $this->writeUploadZip($installId, $slug, version: '1.1.0', readme: "new extension\n");

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->installer()->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => true,
        ]);

        self::assertTrue($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(ExtensionStatus::Active, $this->extensionStatus($slug));
        self::assertSame(ContentStatus::Published, $this->contentStatus('c5000000-0000-7000-8000-000000000001'));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteContentFixture();
        $this->deleteExtensionRow($slug);
    }

    public function testItRebuildsAssetsAfterActivationRollbackDuringActiveOverwrite(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for extension ZIP installer tests.');
        }

        $installId = '151515151515151515151515';
        $slug = 'zip-install-rollback';
        $target = $this->projectDir.'/extensions/'.$slug;
        $assetRebuilder = new RecordingInstallAssetRebuilder();
        $this->removePath($target);
        $this->deleteExtensionRow($slug);
        $this->writeExtensionDirectory($target, $slug, '1.0.0', 'old extension');
        $this->persistExtension($slug, ExtensionStatus::Active);
        $this->writeUploadZip(
            $installId,
            $slug,
            version: '1.1.0',
            readme: "new extension\n",
            extensionPhp: <<<'PHP'
                <?php

                use App\Core\Extension\Database\ExtensionDatabaseColumn;
                use App\Core\Extension\Database\ExtensionDatabaseTable;

                return [
                    ExtensionDatabaseTable::create(
                        'table_name_segment_table_name_segment_table_name_segment_table_name_segment',
                        [ExtensionDatabaseColumn::string('uid', 36)],
                        ['uid'],
                    ),
                ];
                PHP,
        );

        $verify = $this->installer()->verify(['install_id' => $installId]);
        self::assertSame(WorkflowStatus::RequiresReview, $verify->status());

        $apply = $this->applier($assetRebuilder)->apply([
            'install_id' => $installId,
            'extension' => $slug,
            'was_active' => true,
        ]);

        self::assertFalse($apply->isSuccess(), json_encode($apply->toArray(), JSON_THROW_ON_ERROR));
        self::assertTrue($apply->context()['rollback_asset_rebuild'] ?? false);
        self::assertTrue($apply->context()['rollback_asset_rebuild_success'] ?? false);
        self::assertSame(['test'], $assetRebuilder->environments);
        self::assertStringContainsString('EXTENSION_VERSION=1.0.0', (string) file_get_contents($target.'/.manifest'));
        self::assertSame(ExtensionStatus::Active, $this->extensionStatus($slug));

        $this->removePath($target);
        $this->removePath($this->installRoot($installId));
        $this->deleteExtensionRow($slug);
    }

    private function installer(): ExtensionZipInstaller
    {
        $installer = self::getContainer()->get(ExtensionZipInstaller::class);
        self::assertInstanceOf(ExtensionZipInstaller::class, $installer);

        return $installer;
    }

    private function applier(ExtensionLifecycleAssetRebuilderInterface $assetRebuilder): ExtensionInstallApplier
    {
        return new ExtensionInstallApplier(
            self::getContainer()->get(ExtensionDiscoveryRunner::class),
            self::getContainer()->get(ExtensionActivator::class),
            self::getContainer()->get(ExtensionInstallFilesystem::class),
            self::getContainer()->get(ExtensionInstallPayload::class),
            self::getContainer()->get(ExtensionInstallStageReader::class),
            self::getContainer()->get(ExtensionInstallRegistry::class),
            self::getContainer()->get(ExtensionInstallVersionGuard::class),
            self::getContainer()->get(ExtensionReplacementPreflight::class),
            self::getContainer()->get(ExtensionInstallRollbacker::class),
            self::getContainer()->get(ExtensionReactivationPlanner::class),
            $assetRebuilder,
            'test',
        );
    }

    private function writeUploadZip(
        string $installId,
        string $slug,
        string $dependencies = '[]',
        string $version = '1.0.0',
        string $readme = "# ZIP Install Test\n",
        ?string $extensionPhp = null,
        bool $flatRoot = false,
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
        if (null !== $extensionPhp) {
            file_put_contents($source.'/extension.php', $extensionPhp);
        }

        $zip = new ZipArchive();
        $prefix = $flatRoot ? '' : $slug.'/';
        self::assertTrue(true === $zip->open($root.'/upload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($source.'/.manifest', $prefix.'.manifest');
        $zip->addFile($source.'/README.md', $prefix.'README.md');
        if (null !== $extensionPhp) {
            $zip->addFile($source.'/extension.php', $prefix.'extension.php');
        }
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
        array $scopes = [ExtensionScope::Module],
    ): void
    {
        $this->entityManager->persist(new Extension(
            $this->uuid(),
            $scopes,
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

    private function createPublishedExtensionContent(Extension $extension): void
    {
        $schemaSync = new ExtensionContentSchemaSynchronizer($this->entityManager);
        $schemaApply = $schemaSync->apply($extension, [
            ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'string'],
                    ['identifier' => 'subtitle', 'type' => 'string'],
                    ['identifier' => 'body', 'type' => 'text'],
                ],
            ]),
        ]);
        self::assertTrue($schemaApply->isSuccess(), json_encode($schemaApply->toArray(), JSON_THROW_ON_ERROR));

        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy([
            'identifier' => 'ext19_zip_install_content_article',
        ]);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertNotNull($schema->activeVersion());

        $content = new ContentItem('c5000000-0000-7000-8000-000000000001', 'zip-install-content-item');
        $content->activateRevision(new ContentRevision('c5000000-0000-7000-8000-000000000101', $content, 1, $schema->activeVersion()));
        $content->publish();
        $this->entityManager->persist($content);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function contentStatus(string $uid): ContentStatus
    {
        $content = $this->entityManager->find(ContentItem::class, $uid);
        self::assertInstanceOf(ContentItem::class, $content);

        return $content->status();
    }

    private function deleteContentFixture(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("DELETE FROM content_revision WHERE content_uid = 'c5000000-0000-7000-8000-000000000001'");
        $connection->executeStatement("DELETE FROM content_item WHERE uid = 'c5000000-0000-7000-8000-000000000001'");

        $schemaUid = $connection->fetchOne("SELECT uid FROM content_schema WHERE identifier = 'ext19_zip_install_content_article'");
        if (is_string($schemaUid)) {
            $connection->executeStatement('UPDATE content_schema SET active_version_uid = NULL WHERE uid = :schema', ['schema' => $schemaUid]);
            $connection->executeStatement('DELETE FROM content_schema_version WHERE schema_uid = :schema', ['schema' => $schemaUid]);
            $connection->executeStatement('DELETE FROM content_schema WHERE uid = :schema', ['schema' => $schemaUid]);
        }

        $this->entityManager->clear();
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

final class RecordingInstallAssetRebuilder implements ExtensionLifecycleAssetRebuilderInterface
{
    /**
     * @var list<string>
     */
    public array $environments = [];

    public function rebuild(string $environment): WorkflowResult
    {
        $this->environments[] = $environment;

        return WorkflowResult::success(context: ['environment' => $environment]);
    }
}
