<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageDependencyResolver;
use App\Core\Package\PackageLifecycleAssetRebuilderInterface;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\View\SystemPackageMetadataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageActivatorTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FakePackageLifecycleAssetRebuilder $assetRebuilder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->assetRebuilder = new FakePackageLifecycleAssetRebuilder();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItActivatesInactivePackagesAndRunsAssetRebuild(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive');

        $result = $this->activator()->activate('demo-module', 'test');

        self::assertTrue($result->isSuccess());
        self::assertSame('active', $this->packageStatus('demo-module'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'activated',
            'status' => 'active',
        ]], $result->value()['changes']);
    }

    public function testItDeactivatesConflictingSingleActiveScopes(): void
    {
        $this->insertPackage('old-theme', ['frontend-theme'], 'active');
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive');
        $this->insertPackage('utility-module', ['module'], 'active');

        $result = $this->activator()->activate('new-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('old-theme'));
        self::assertSame('active', $this->packageStatus('new-theme'));
        self::assertSame('active', $this->packageStatus('utility-module'));
        self::assertSame(['old-theme', 'new-theme'], array_column($result->value()['changes'], 'package'));
        self::assertSame([], $this->assetRebuilder->environments);
    }

    public function testItDeactivatesDependentsOfConflictingSingleActiveScopes(): void
    {
        $this->insertPackage('old-theme', ['frontend-theme'], 'active');
        $this->insertPackage('captcha-provider', ['captcha-provider'], 'active', "[['old-theme', '1.0.0']]");
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive');

        $result = $this->activator()->activate('new-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('old-theme'));
        self::assertSame('inactive', $this->packageStatus('captcha-provider'));
        self::assertSame('active', $this->packageStatus('new-theme'));
        self::assertSame(['captcha-provider', 'old-theme', 'new-theme'], array_column($result->value()['changes'], 'package'));
    }

    public function testItRunsOneAssetRebuildForBatchedActivationChanges(): void
    {
        $this->insertPackage('old-theme', ['frontend-theme'], 'active');
        $this->insertPackage('theme-tools', ['module'], 'inactive');
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.0.0']]");

        $result = $this->activator()->activate('new-theme', 'test');

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('old-theme'));
        self::assertSame('active', $this->packageStatus('new-theme'));
        self::assertSame('active', $this->packageStatus('theme-tools'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame(['old-theme', 'new-theme', 'theme-tools'], array_column($result->value()['changes'], 'package'));
    }

    public function testItPlansDependenciesAndConflictsBeforeActivation(): void
    {
        $this->insertPackage('old-theme', ['frontend-theme'], 'active');
        $this->insertPackage('theme-tools', ['module'], 'inactive');
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.0.0']]");

        $result = $this->activator()->planActivation('new-theme');

        self::assertTrue($result->isSuccess());
        self::assertSame(['new-theme', 'theme-tools'], $result->value()['activate']);
        self::assertSame(['old-theme'], $result->value()['deactivate']);
        self::assertSame([[
            'package' => 'theme-tools',
            'required_min_version' => '1.0.0',
            'installed_version' => '1.0.0',
            'status' => 'inactive',
            'required_by' => 'new-theme',
        ]], $result->value()['dependencies']);
        self::assertSame('package.dependency.resolved', $result->messages()[0]->code());
        self::assertSame(MessageLevel::Debug, $result->messages()[0]->level());
    }

    public function testItActivatesInactiveDependenciesWithTheTargetPackage(): void
    {
        $this->insertPackage('theme-tools', ['module'], 'inactive');
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.0.0']]");

        $result = $this->activator()->activate('new-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('active', $this->packageStatus('new-theme'));
        self::assertSame('active', $this->packageStatus('theme-tools'));
        self::assertSame(['new-theme', 'theme-tools'], array_column($result->value()['changes'], 'package'));
    }

    public function testItTreatsSystemAsSatisfiedVirtualDependency(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive', '[["system", "0.1.0"]]');

        $result = $this->activatorWithSystemDependencySupport()->planActivation('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $result->value()['activate']);
        self::assertSame([[
            'package' => 'system',
            'required_min_version' => '0.1.0',
            'installed_version' => '0.1.0',
            'status' => 'active',
            'required_by' => 'demo-module',
        ]], $result->value()['dependencies']);
    }

    public function testItBlocksMissingPackageDependencies(): void
    {
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive', "[['missing-tools', '1.0.0']]");

        $result = $this->activator()->planActivation('new-theme');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.dependency.missing', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->packageStatus('new-theme'));
    }

    public function testItBlocksMalformedPackageDependencies(): void
    {
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive', '["theme-tools >=1.0"]');

        $result = $this->activator()->planActivation('new-theme');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.dependency.invalid', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->packageStatus('new-theme'));
    }

    public function testItBlocksUnsatisfiedPackageDependencyVersions(): void
    {
        $this->insertPackage('theme-tools', ['module'], 'active', version: '1.0.0');
        $this->insertPackage('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.1.0']]");

        $result = $this->activator()->planActivation('new-theme');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.dependency.version_unsatisfied', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->packageStatus('new-theme'));
    }

    public function testItBlocksCircularPackageDependencies(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive', "[['demo-tools', '1.0.0']]");
        $this->insertPackage('demo-tools', ['module'], 'inactive', "[['demo-module', '1.0.0']]");

        $result = $this->activator()->planActivation('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.dependency.cycle', $result->firstIssue()?->code());
        self::assertSame(['demo-module', 'demo-tools', 'demo-module'], $result->firstIssue()?->context()['cycle']);
        self::assertSame('inactive', $this->packageStatus('demo-module'));
        self::assertSame('inactive', $this->packageStatus('demo-tools'));
    }

    public function testItBlocksPackageSelfDependencies(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive', "[['demo-module', '1.0.0']]");

        $result = $this->activator()->planActivation('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.dependency.cycle', $result->firstIssue()?->code());
        self::assertSame(['demo-module', 'demo-module'], $result->firstIssue()?->context()['cycle']);
        self::assertSame('inactive', $this->packageStatus('demo-module'));
    }

    public function testItDeactivatesActivePackages(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');

        $result = $this->activator()->deactivate('demo-module', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('demo-module'));
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'deactivated',
            'status' => 'inactive',
        ]], $result->value()['changes']);
    }

    public function testItPlansDependentCascadeBeforeDeactivation(): void
    {
        $this->insertPackage('demo-theme', ['frontend-theme'], 'active');
        $this->insertPackage('captcha-provider', ['captcha-provider'], 'active', "[['demo-theme', '1.0.0']]");

        $result = $this->activator()->planDeactivation('demo-theme');

        self::assertTrue($result->isSuccess());
        self::assertSame(['captcha-provider', 'demo-theme'], $result->value()['deactivate']);
        self::assertSame([
            [
                'package' => 'captcha-provider',
                'action' => 'deactivated',
                'status' => 'inactive',
            ],
            [
                'package' => 'demo-theme',
                'action' => 'deactivated',
                'status' => 'inactive',
            ],
        ], $result->value()['changes']);
    }

    public function testItDeactivatesActiveDependentsWithTheTargetPackage(): void
    {
        $this->insertPackage('demo-theme', ['frontend-theme'], 'active');
        $this->insertPackage('captcha-provider', ['captcha-provider'], 'active', "[['demo-theme', '1.0.0']]");

        $result = $this->activator()->deactivate('demo-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('demo-theme'));
        self::assertSame('inactive', $this->packageStatus('captcha-provider'));
        self::assertSame(['captcha-provider', 'demo-theme'], array_column($result->value()['changes'], 'package'));
    }

    public function testItBlocksFaultyOrRemovedPackages(): void
    {
        foreach (['faulty', 'removed'] as $status) {
            $this->insertPackage('demo-'.$status, ['module'], $status);

            $result = $this->activator()->activate('demo-'.$status, 'test', rebuildAssets: false);

            self::assertFalse($result->isSuccess());
            self::assertSame('package.lifecycle.status_blocked', $result->firstIssue()?->code());
            self::assertSame($status, $this->packageStatus('demo-'.$status));
        }
    }

    public function testItRollsBackWhenAssetRebuildFails(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive');
        $this->assetRebuilder->result = WorkflowResult::failed([
            Message::create(
                MessageCode::PACKAGE_ASSET_SYNC_FAILED,
                MessageKey::PACKAGE_ASSET_SYNC_FAILED,
                ['%message%' => 'rebuild failed'],
                level: MessageLevel::Error,
            ),
        ]);

        $result = $this->activator()->activate('demo-module', 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('demo-module'));
        self::assertTrue($result->context()['rolled_back']);
    }

    public function testItReportsMissingPackages(): void
    {
        $result = $this->activator()->activate('missing', 'test', rebuildAssets: false);

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.not_found', $result->firstIssue()?->code());
    }

    private function activator(): PackageActivator
    {
        return new PackageActivator($this->entityManager, $this->assetRebuilder, new NullWorkflowResultMessageReporter());
    }

    private function activatorWithSystemDependencySupport(): PackageActivator
    {
        return new PackageActivator(
            $this->entityManager,
            $this->assetRebuilder,
            new NullWorkflowResultMessageReporter(),
            new PackageDependencyResolver($this->entityManager, new SystemPackageMetadataProvider(dirname(__DIR__, 3))),
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function insertPackage(
        string $packageName,
        array $scopes,
        string $status,
        string $dependencies = '[]',
        string $version = '1.0.0',
    ): void {
        $this->connection->insert('extension_package', [
            'uid' => $this->uuid(),
            'package_scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'package_name' => $packageName,
            'path' => 'packages/'.$packageName,
            'manifest_version' => $version,
            'installed_version' => $version,
            'status' => $status,
            'metadata' => json_encode([
                'registry_state' => 'available',
                'manifest' => [
                    'PACKAGE_DEPENDENCIES' => $dependencies,
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    private function packageStatus(string $packageName): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsString($status);

        return $status;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

final class FakePackageLifecycleAssetRebuilder implements PackageLifecycleAssetRebuilderInterface
{
    /**
     * @var list<string>
     */
    public array $environments = [];

    /**
     * @var WorkflowResult<mixed>
     */
    public WorkflowResult $result;

    public function __construct()
    {
        $this->result = WorkflowResult::success(context: ['fake' => true]);
    }

    public function rebuild(string $environment): WorkflowResult
    {
        $this->environments[] = $environment;

        return $this->result;
    }
}
