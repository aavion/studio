<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Api\ApiFeaturePolicy;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RateLimitEnforcementControllerTest extends WebTestCase
{
    use AuthenticatedClientTrait;

    public function testBrowserRateLimitRendersHtmlErrorWithSafeHeaders(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.10'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 16; ++$i) {
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
        self::assertStringContainsString('Invalid Request', $client->getResponse()->getContent());
        self::assertStringContainsString('Request-ID', $client->getResponse()->getContent());
        self::assertStringNotContainsString('suspicious.probe', $client->getResponse()->getContent());
    }

    public function testLiveSuspiciousProbeIsBlockedBeforeLiveApiExclusion(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.17'));
        $this->setMode(RateLimitProfile::Off);

        $client->request('GET', '/api/live/.env');

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testApiSuspiciousProbeIsBlockedBeforeApiDisabledGate(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.27'));
        $this->setMode(RateLimitProfile::Off);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        try {
            $config->set(ApiFeaturePolicy::ENABLED_KEY, false, ConfigValueType::Boolean);

            $client->request('GET', '/api/v1/.env');

            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        } finally {
            $config->set(ApiFeaturePolicy::ENABLED_KEY, true, ConfigValueType::Boolean);
        }
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
        $client = self::createClient(server: $this->server('198.51.100.16'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 30; ++$i) {
            $client->request('GET', '/api/v1', server: [
                'HTTP_AUTHORIZATION' => sprintf('Bearer invalid%02d.invalid-secret', $i),
            ]);
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('GET', '/api/v1', server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid31.invalid-secret',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testInvalidBearerOptionsRequestsSpendApiBudgetBeforeAuthenticationResponse(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.22'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 30; ++$i) {
            $client->request('OPTIONS', '/api/v1/status', server: [
                'HTTP_AUTHORIZATION' => sprintf('Bearer option%02d.invalid-secret', $i),
            ]);
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('OPTIONS', '/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer option31.invalid-secret',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testCorsBearerPreflightsSpendAuthFailureBudgetBeforeCorsShortCircuit(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.28'));
        $this->setMode(RateLimitProfile::Panic);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        try {
            $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, true, ConfigValueType::Boolean);
            $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, ['https://client.example'], ConfigValueType::Json);

            for ($i = 0; $i < 7; ++$i) {
                $client->request('OPTIONS', '/api/v1/admin/settings/general', server: [
                    'HTTP_ORIGIN' => 'https://client.example',
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                    'HTTP_AUTHORIZATION' => sprintf('Bearer corsadm%02d.invalid-secret', $i),
                ]);
                self::assertNotSame(204, $client->getResponse()->getStatusCode());
                self::assertNotSame(429, $client->getResponse()->getStatusCode());
            }

            $client->request('OPTIONS', '/api/v1/admin/settings/general', server: [
                'HTTP_ORIGIN' => 'https://client.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_AUTHORIZATION' => 'Bearer corsadm08.invalid-secret',
            ]);

            self::assertResponseStatusCodeSame(429);
        } finally {
            $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, false, ConfigValueType::Boolean);
            $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, [], ConfigValueType::Json);
        }
    }

    public function testMalformedBearerPreflightsSpendAuthFailureBudgetBeforeCorsShortCircuit(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.30'));
        $this->setMode(RateLimitProfile::Panic);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        try {
            $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, true, ConfigValueType::Boolean);
            $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, ['https://client.example'], ConfigValueType::Json);

            for ($i = 0; $i < 7; ++$i) {
                $client->request('OPTIONS', '/api/v1/admin/settings/general', server: [
                    'HTTP_ORIGIN' => 'https://client.example',
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                    'HTTP_AUTHORIZATION' => 'Bearer   ',
                ]);
                self::assertNotSame(204, $client->getResponse()->getStatusCode());
                self::assertNotSame(429, $client->getResponse()->getStatusCode());
            }

            $client->request('OPTIONS', '/api/v1/admin/settings/general', server: [
                'HTTP_ORIGIN' => 'https://client.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_AUTHORIZATION' => 'Bearer   ',
            ]);

            self::assertResponseStatusCodeSame(429);
        } finally {
            $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, false, ConfigValueType::Boolean);
            $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, [], ConfigValueType::Json);
        }
    }

    public function testRecoveryLoginRendersSpendRecoveryBucket(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.23'));
        $this->setMode(RateLimitProfile::Standard);

        $client->request('GET', '/user/login?bypass=1');
        self::assertNotSame(429, $client->getResponse()->getStatusCode());
        $client->request('GET', '/user/login?bypass=1');
        self::assertNotSame(429, $client->getResponse()->getStatusCode());

        $client->request('GET', '/user/login?bypass=1');

        self::assertResponseStatusCodeSame(429);
    }

    public function testPanicRecoveryLoginRenderAndSubmitAreNotRateLimited(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.26'));
        $this->setMode(RateLimitProfile::Panic);

        $client->request('GET', '/user/login?bypass=1');
        self::assertNotSame(429, $client->getResponse()->getStatusCode());

        $client->request('POST', '/user/login', parameters: [
            'username' => 'missing-recovery-user',
            'password' => 'wrong-password',
        ]);

        self::assertNotSame(429, $client->getResponse()->getStatusCode());
    }

    public function testRotatingInvalidBearerPrefixesDoNotBypassApiWriteBudget(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.18'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 15; ++$i) {
            $client->request('POST', '/api/v1/status', server: [
                'HTTP_AUTHORIZATION' => sprintf('Bearer write%02d.invalid-secret', $i),
            ]);
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('POST', '/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer write16.invalid-secret',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testInvalidBearerAdminMutationsSpendAuthFailureBudget(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.21'));
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 7; ++$i) {
            $client->request('PATCH', '/api/v1/admin/settings/general', server: [
                'HTTP_AUTHORIZATION' => sprintf('Bearer admin%02d.invalid-secret', $i),
            ]);
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }

        $client->request('PATCH', '/api/v1/admin/settings/general', server: [
            'HTTP_AUTHORIZATION' => 'Bearer admin08.invalid-secret',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testReadOnlyOwnerApiKeyMutationsSpendApiWriteBudgetBeforeDenial(): void
    {
        $prefix = 'rlownro';
        $client = self::createClient(server: $this->server('198.51.100.24'));
        $plainKey = $this->createOwnerApiKey($prefix, ApiKeyStatus::ReadOnly);

        try {
            $this->setMode(RateLimitProfile::Panic);

            for ($i = 0; $i < 15; ++$i) {
                $client->request('POST', '/api/v1/status', server: [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                ]);
                self::assertNotSame(429, $client->getResponse()->getStatusCode());
            }

            $client->request('POST', '/api/v1/status', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseStatusCodeSame(429);
        } finally {
            $this->removeApiKey($prefix);
        }
    }

    public function testReadOnlyOwnerApiKeyUnsafePreflightsSpendAdminBudgetBeforeDenial(): void
    {
        $prefix = 'rlownpf';
        $client = self::createClient(server: $this->server('198.51.100.29'));
        $plainKey = $this->createOwnerApiKey($prefix, ApiKeyStatus::ReadOnly);
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        try {
            $this->setMode(RateLimitProfile::Panic);
            $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, true, ConfigValueType::Boolean);
            $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, ['https://client.example'], ConfigValueType::Json);

            for ($i = 0; $i < 7; ++$i) {
                $client->request('OPTIONS', '/api/v1/admin/settings/general', server: [
                    'HTTP_ORIGIN' => 'https://client.example',
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                ]);
                self::assertResponseStatusCodeSame(403);
            }

            $client->request('OPTIONS', '/api/v1/admin/settings/general', server: [
                'HTTP_ORIGIN' => 'https://client.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseStatusCodeSame(429);
        } finally {
            $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, false, ConfigValueType::Boolean);
            $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, [], ConfigValueType::Json);
            $this->removeApiKey($prefix);
        }
    }

    public function testSchedulerIntervalUsesStableBearerCredentialAcrossVisitorChanges(): void
    {
        $first = self::createClient(server: [
            ...$this->server('198.51.100.25'),
            'HTTP_USER_AGENT' => 'SchedulerProbe/1',
        ]);
        $this->setMode(RateLimitProfile::Standard);

        $first->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
        ]);
        self::assertNotSame(429, $first->getResponse()->getStatusCode());

        self::ensureKernelShutdown();

        $second = self::createClient(server: [
            ...$this->server('198.51.100.25'),
            'HTTP_USER_AGENT' => 'SchedulerProbe/2',
        ]);
        $second->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testSignedInSchedulerIntervalKeepsIpAnchorAcrossRotatingQueryCredentials(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.31'));
        $this->loginTestUser($client, $this->adminUser());
        $this->setMode(RateLimitProfile::Standard);

        $client->request('GET', '/cron/run?auth=scheduler-token-a');
        self::assertNotSame(429, $client->getResponse()->getStatusCode());

        $client->request('GET', '/cron/run?auth=scheduler-token-b');

        self::assertResponseStatusCodeSame(429);
    }

    public function testValidOwnerApiKeyUsesPostAuthOwnerExemption(): void
    {
        $prefix = 'rlowner';
        $client = self::createClient(server: $this->server('198.51.100.19'));
        $plainKey = $this->createOwnerApiKey($prefix);

        try {
            $this->setMode(RateLimitProfile::Panic);

            for ($i = 0; $i < 35; ++$i) {
                $client->request('GET', '/api/v1', server: [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                ]);
                self::assertNotSame(429, $client->getResponse()->getStatusCode());
            }
        } finally {
            $this->removeApiKey($prefix);
        }
    }

    public function testSignedInOwnerUsesPostAuthOwnerExemption(): void
    {
        $client = self::createClient(server: $this->server('198.51.100.20'));
        $this->loginTestUser($client, $this->adminUser());
        $this->setMode(RateLimitProfile::Panic);

        for ($i = 0; $i < 12; ++$i) {
            $client->request('GET', '/home');
            self::assertNotSame(429, $client->getResponse()->getStatusCode());
        }
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

    private function adminUser(): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'admin']);
        self::assertInstanceOf(UserAccount::class, $user);

        return $user;
    }

    private function createOwnerApiKey(string $prefix, ApiKeyStatus $status = ApiKeyStatus::ReadWrite): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->removeApiKey($prefix);

        $vault = self::getContainer()->get(ApiKeyVault::class);
        self::assertInstanceOf(ApiKeyVault::class, $vault);

        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '63000000-0000-7000-8000-'.substr(md5($prefix), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $this->adminUser(),
            $status,
        );

        $entityManager->persist($apiKey);
        $entityManager->flush();

        return $plainKey;
    }

    private function removeApiKey(string $prefix): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $existing = $entityManager->getRepository(ApiKey::class)->findOneBy(['prefix' => $prefix]);
        if ($existing instanceof ApiKey) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }
    }
}
