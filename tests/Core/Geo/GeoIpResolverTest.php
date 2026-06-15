<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Geo\GeoIpProviderInterface;
use App\Core\Geo\GeoIpProviderStatus;
use App\Core\Geo\GeoIpResolver;
use App\Core\Geo\GeoIpResult;
use App\Core\Geo\NullGeoIpProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeoIpResolverTest extends TestCase
{
    public function testItReturnsPlaceholdersWhenNoProviderIsReady(): void
    {
        $resolver = new GeoIpResolver([
            new FakeGeoIpProvider(GeoIpProviderStatus::unconfigured('maxmind')),
        ], new NullGeoIpProvider());

        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $resolver->resolve('203.0.113.10')->toArray());

        self::assertSame('none', $resolver->status()->providerKey);
        self::assertSame('disabled', $resolver->status()->status);
    }

    public function testItUsesFirstReadyProvider(): void
    {
        $resolver = new GeoIpResolver([
            new FakeGeoIpProvider(GeoIpProviderStatus::unconfigured('disabled')),
            new FakeGeoIpProvider(
                GeoIpProviderStatus::ready('maxmind', databaseEdition: 'GeoLite2-City'),
                new GeoIpResult('Berlin', 'Berlin', 'Germany', 'Europe'),
            ),
        ], new NullGeoIpProvider());

        self::assertSame([
            'city' => 'Berlin',
            'state' => 'Berlin',
            'country' => 'Germany',
            'continent' => 'Europe',
        ], $resolver->resolve('203.0.113.10')->toArray());
        self::assertSame('maxmind', $resolver->status()->providerKey);
        self::assertSame('GeoLite2-City', $resolver->status()->databaseEdition);
    }

    public function testItFallsBackWhenReadyProviderThrows(): void
    {
        $resolver = new GeoIpResolver([
            new FakeGeoIpProvider(GeoIpProviderStatus::ready('maxmind'), throws: true),
        ], new NullGeoIpProvider());

        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $resolver->resolve('203.0.113.10')->toArray());
    }

    public function testItDoesNotCallProvidersWithoutAnIpAddress(): void
    {
        $provider = new FakeGeoIpProvider(
            GeoIpProviderStatus::ready('maxmind'),
            new GeoIpResult('Berlin', 'Berlin', 'Germany', 'Europe'),
        );
        $resolver = new GeoIpResolver([$provider], new NullGeoIpProvider());

        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $resolver->resolve('')->toArray());
        self::assertSame(0, $provider->lookupCount);
    }

    public function testProviderStatusContainsOnlySafeDiagnosticFields(): void
    {
        $status = GeoIpProviderStatus::ready(
            'maxmind',
            databaseEdition: 'GeoLite2-City',
            databaseBuildDate: '2026-06-15',
        );

        self::assertSame([
            'provider_key' => 'maxmind',
            'status' => 'ready',
            'database_edition' => 'GeoLite2-City',
            'database_build_date' => '2026-06-15',
            'failure_code' => null,
        ], $status->toSafeArray());
    }
}

final class FakeGeoIpProvider implements GeoIpProviderInterface
{
    public int $lookupCount = 0;

    public function __construct(
        private readonly GeoIpProviderStatus $status,
        private readonly GeoIpResult $result = new GeoIpResult(),
        private readonly bool $throws = false,
    ) {
    }

    public function key(): string
    {
        return $this->status->providerKey;
    }

    public function status(): GeoIpProviderStatus
    {
        return $this->status;
    }

    public function resolve(?string $ipAddress): GeoIpResult
    {
        ++$this->lookupCount;

        if ($this->throws) {
            throw new RuntimeException('GeoIP provider failure');
        }

        return $this->result;
    }
}
