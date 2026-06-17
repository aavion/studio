<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RateLimitEnforcementControllerTest extends WebTestCase
{
    public function testBrowserRateLimitRendersHtmlErrorWithSafeHeaders(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.10'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 7; ++$i) {
            $client->request('GET', '/home');
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('GET', '/home');

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('Request ID', $client->getResponse()->getContent());
        self::assertStringNotContainsString('website.deliberate', $client->getResponse()->getContent());
        self::assertStringNotContainsString('ip_bucket', $client->getResponse()->getContent());
    }

    public function testApiRateLimitReturnsJsonWithRequestIdAndNoInternalDetails(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.11'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 30; ++$i) {
            $client->request('GET', '/api/v1');
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('GET', '/api/v1');

        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));

        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('rate_limit.exceeded', $payload['error']['code']);
        self::assertArrayHasKey('request_id', $payload['error']['context']);
        self::assertStringNotContainsString('api.public_read', $client->getResponse()->getContent());
        self::assertStringNotContainsString('ip_bucket', $client->getResponse()->getContent());
    }

    public function testSuspiciousProbeReturnsGenericBadRequestEvenWhenModeIsOff(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.12'));
        $this->setMode(RateLimitProfile::Off);

        $client->request('GET', '/.env');

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Request ID', $client->getResponse()->getContent());
        self::assertStringNotContainsString('suspicious.probe', $client->getResponse()->getContent());
    }

    public function testPrefetchAndLiveApiPathsAreNotChargedToOrdinaryLimiter(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.13'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 12; ++$i) {
            $client->request('GET', '/home', server: ['HTTP_SEC_PURPOSE' => 'prefetch']);
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
            $client->request('GET', '/api/live/status');
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }
    }

    public function testBuildAssetsAreNotChargedToOrdinaryLimiter(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.14'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 12; ++$i) {
            $client->request('GET', '/build/app.js');
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }
    }

    public function testInvalidLoginSubmissionsSpendLoginBudgetBeforeAuthenticationResponse(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.15'));
        $this->setMode(RateLimitProfile::Standard);

        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/user/login', parameters: [
                'username' => 'missing-user',
                'password' => 'wrong-password',
            ]);
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('POST', '/user/login', parameters: [
            'username' => 'missing-user',
            'password' => 'wrong-password',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testInvalidBearerRequestsSpendApiBudgetBeforeAuthenticationResponse(): void
    {
        $client = self::createClient(server: [
            ...$this->server('198.51.100.16'),
            'HTTP_AUTHORIZATION' => 'Bearer invalidprefix.invalid-secret',
        ]);
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 30; ++$i) {
            $client->request('GET', '/api/v1');
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('GET', '/api/v1');

        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @return array<string, string>
     */
    private function server(string $ip): array
    {
        return [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => 'RateLimitEnforcementControllerTest',
            'HTTP_X_RATE_LIMIT_TESTING' => '1',
        ];
    }

    private function setMode(RateLimitProfile $profile): void
    {
        $cache = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, $profile->value, ConfigValueType::String, modifiedBy: 'test');
    }
}
