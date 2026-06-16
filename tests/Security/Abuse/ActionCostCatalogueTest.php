<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ActionCostCatalogueTest extends TestCase
{
    public function testItKeepsLiveAndPrefetchTrafficOutOfOrdinaryEnforcement(): void
    {
        $classifier = new RequestIntentClassifier();
        $catalogue = new ActionCostCatalogue();

        $live = $catalogue->costFor($classifier->classify(Request::create('/api/live/alerts')));
        $prefetch = $catalogue->costFor($classifier->classify(Request::create('/docs', server: [
            'HTTP_SEC_PURPOSE' => 'prefetch',
        ])));

        self::assertSame('live_api', $live->bucketFamily());
        self::assertSame(0, $live->credits());
        self::assertFalse($live->ordinaryEnforcement());
        self::assertSame('website_prefetch', $prefetch->bucketFamily());
        self::assertFalse($prefetch->ordinaryEnforcement());
    }

    public function testItAssignsHigherSymbolicCostsToSuspiciousAndMutatingTraffic(): void
    {
        $classifier = new RequestIntentClassifier();
        $catalogue = new ActionCostCatalogue();

        $probe = $catalogue->costFor($classifier->classify(Request::create('/.env')));
        $apiWrite = $catalogue->costFor($classifier->classify(Request::create('/api/v1/content/items', 'POST')));
        $setupApply = $catalogue->costFor($classifier->classify(Request::create('/setup', 'POST')));

        self::assertSame('suspicious_probe', $probe->bucketFamily());
        self::assertSame(10, $probe->credits());
        self::assertSame('api_write', $apiWrite->bucketFamily());
        self::assertSame(5, $apiWrite->credits());
        self::assertSame('setup_apply', $setupApply->bucketFamily());
        self::assertSame(8, $setupApply->credits());
    }
}
