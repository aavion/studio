<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageDiscovery;
use App\Core\Package\PackageDiscoveryCacheWarmer;
use App\Core\Package\PackageDiscoveryDispatcher;
use App\Core\Package\PackageDiscoveryMessage;
use App\Core\Package\PackageDiscoveryMessageHandler;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Package\PackageRegistryHandler;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageDiscoveryRunnerTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('studio-package-discovery-runner');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testItDiscoversValidPackagesAndSynchronizesTheRegistry(): void
    {
        $this->writePackageManifest('demo-module', 'module');

        $result = ($this->runner())('admin_refresh');

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('admin_refresh', $result->context()['trigger']);
        self::assertSame(1, $result->value()['candidate_count']);
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'registered',
            'status' => 'inactive',
        ]], $result->value()['changes']);

        $row = $this->packageRow('demo-module');
        self::assertSame('inactive', $row['status']);
        self::assertSame('packages/demo-module', $row['path']);
    }

    public function testItDoesNotMutateTheRegistryWhenDiscoveryFails(): void
    {
        $this->insertPackage('known-module', 'packages/known-module', 'active');
        $this->writePackageManifest('broken-module', 'unknown-scope');

        $result = ($this->runner())('manual');

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $result->context()['candidate_count']);
        self::assertSame('active', $this->packageRow('known-module')['status']);
    }

    public function testMessageHandlerRunsDiscoveryWithMessageTrigger(): void
    {
        $this->writePackageManifest('message-module', 'module');

        $result = (new PackageDiscoveryMessageHandler($this->runner()))(new PackageDiscoveryMessage('messenger'));

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('messenger', $result->context()['trigger']);
        self::assertSame('inactive', $this->packageRow('message-module')['status']);
    }

    public function testCacheWarmerQueuesDiscoveryWithCacheWarmupTrigger(): void
    {
        $cacheDir = $this->projectDir.'/var/cache/test';
        $messageBus = new RecordingMessageBus();

        $classes = (new PackageDiscoveryCacheWarmer(new PackageDiscoveryDispatcher($messageBus, new NullWorkflowResultMessageReporter())))->warmUp($cacheDir);

        self::assertSame([], $classes);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageDiscoveryMessage::class, $messageBus->messages()[0]);
        self::assertSame('cache_warmup', $messageBus->messages()[0]->trigger());
        self::assertFileExists($cacheDir.'/studio-package-discovery-warmup.lock');

        $payload = json_decode(
            (string) file_get_contents($cacheDir.'/studio-package-discovery-warmup.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('success', $payload['status']);
        self::assertSame('cache_warmup', $payload['context']['trigger']);
        self::assertTrue($payload['context']['deferred']);
    }

    private function runner(): PackageDiscoveryRunner
    {
        return new PackageDiscoveryRunner(
            new PackageDiscovery(),
            new PackageRegistryHandler($this->entityManager, $this->projectDir),
            $this->projectDir,
            'test',
            new NullWorkflowResultMessageReporter(),
        );
    }

    private function writePackageManifest(string $slug, string $scope): void
    {
        $this->writeTestFile($this->projectDir, 'packages/'.$slug.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion
            PACKAGE_SLUG={$slug}
            PACKAGE_NAME=Demo Module
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE={$scope}
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
    }

    private function insertPackage(string $packageName, string $path, string $status): void
    {
        $this->connection->insert('extension_package', [
            'uid' => $this->uuid(),
            'package_scopes' => json_encode(['module'], JSON_THROW_ON_ERROR),
            'package_name' => $packageName,
            'path' => $path,
            'manifest_version' => '1.0.0',
            'installed_version' => null,
            'status' => $status,
            'metadata' => json_encode(['registry_state' => 'available'], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function packageRow(string $packageName): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsArray($row);

        return $row;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
