<?php

declare(strict_types=1);

namespace App\Tests\Core\AdminAcl;

use App\Core\AdminAcl\AdminFeatureDefinition;
use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminFeatureProviderInterface;
use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Config\Config;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AdminFeatureCacheTest extends TestCase
{
    public function testFeatureRegistryCachesProviderMatrix(): void
    {
        $cache = new ArrayAdapter();
        $provider = new CountingFeatureProvider();

        self::assertCount(1, (new AdminFeatureRegistry([$provider], $cache))->definitions());
        self::assertCount(1, (new AdminFeatureRegistry([$provider], $cache))->definitions());
        self::assertSame(1, $provider->calls);

        (new AdminFeatureRegistry([$provider], $cache))->resetCache();

        self::assertCount(1, (new AdminFeatureRegistry([$provider], $cache))->definitions());
        self::assertSame(2, $provider->calls);
    }

    public function testFeatureOverrideStoreCachesConfiguredOverrides(): void
    {
        $connection = $this->createMock(Connection::class);
        $cache = new ArrayAdapter();
        $payload = [
            'admin.settings.api' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ];

        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT value FROM config_entry WHERE config_key = ?', [AdminFeatureOverrideStore::CONFIG_KEY])
            ->willReturn(json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame($payload, (new AdminFeatureOverrideStore(new Config($connection), cache: $cache))->overrides());
        self::assertSame($payload, (new AdminFeatureOverrideStore(new Config($connection), cache: $cache))->overrides());
    }
}

final class CountingFeatureProvider implements AdminFeatureProviderInterface
{
    public int $calls = 0;

    public function adminFeatures(): array
    {
        ++$this->calls;

        return [
            new AdminFeatureDefinition(
                'admin.settings.api',
                'admin.acl.features.admin_settings_api.label',
                'admin.acl.features.admin_settings_api.description',
                'admin.acl.categories.settings',
            ),
        ];
    }
}
