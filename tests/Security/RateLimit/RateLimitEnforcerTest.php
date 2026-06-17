<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\UserAccount;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\RateLimit\RateLimitEnforcer;
use App\Security\RateLimit\RateLimitLimiterFactory;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use App\Security\RateLimit\RateLimitSubjectSelector;
use App\Security\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class RateLimitEnforcerTest extends TestCase
{
    public function testOffModeDoesNotConsumeLimiterStorage(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Off->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config, cachePool: new FailingCachePool());

        for ($i = 0; $i < 40; ++$i) {
            self::assertTrue($enforcer->check($this->request('/home'))->isAllowed());
        }
    }

    public function testStorageFailureFailsOpenWithDiagnosticsFlag(): void
    {
        $result = $this->enforcer(cachePool: new FailingCachePool())->check($this->request('/home'));

        self::assertTrue($result->isAllowed());
        self::assertTrue($result->storageDegraded());
    }

    public function testLoginWorkflowRejectsBeforeWebsiteBudget(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login', 'POST'))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/login', 'POST'));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.login', $result->diagnosticsLabel());
    }

    public function testOwnerIsExemptFromOrdinaryRateLimitRejection(): void
    {
        $tokenStorage = $this->tokenStorage(UserRole::Owner);
        $enforcer = $this->enforcer(tokenStorage: $tokenStorage);

        for ($i = 0; $i < 40; ++$i) {
            self::assertTrue($enforcer->check($this->request('/home'))->isAllowed());
        }
    }

    public function testAuthenticatedUsersReceiveWebsiteMultiplier(): void
    {
        $tokenStorage = $this->tokenStorage(UserRole::User);
        $enforcer = $this->enforcer(tokenStorage: $tokenStorage);

        for ($i = 0; $i < RateLimitPolicyCatalogue::AUTHENTICATED_MULTIPLIER * 30; ++$i) {
            self::assertTrue($enforcer->check($this->request('/home'))->isAllowed());
        }

        $result = $enforcer->check($this->request('/home'));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.website_burst', $result->diagnosticsLabel());
    }

    public function testSuspiciousProbeStillBlocksInOffModeWithoutStorage(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Off->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config, cachePool: new FailingCachePool());

        $result = $enforcer->check($this->request('/.env'));

        self::assertFalse($result->isAllowed());
        self::assertTrue($result->suspiciousProbe());
        self::assertFalse($result->storageDegraded());
    }

    private function enforcer(?Config $config = null, ?TokenStorage $tokenStorage = null, ?CacheItemPoolInterface $cachePool = null): RateLimitEnforcer
    {
        $tokenStorage ??= new TokenStorage();
        $inspector = new AbuseRequestInspector(
            new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), $tokenStorage, 'test-secret'),
            new RequestIntentClassifier(),
            new ActionCostCatalogue(),
        );

        return new RateLimitEnforcer(
            $inspector,
            $config ?? new Config($this->connection()),
            new RateLimitPolicyCatalogue(),
            new RateLimitSubjectSelector(),
            new RateLimitLimiterFactory($cachePool ?? new ArrayAdapter()),
            new NullLogger(),
        );
    }

    private function request(string $path, string $method = 'GET'): Request
    {
        return Request::create($path, $method, server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'RateLimitEnforcerTest',
        ]);
    }

    private function tokenStorage(UserRole $role): TokenStorage
    {
        $user = new UserAccount(
            '99999999-0000-7000-8000-000000000001',
            'rate_limit_'.$role->value,
            'rate-limit-'.$role->value.'@example.test',
            'hash',
            role: $role,
        );
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $tokenStorage;
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}

final class FailingCachePool implements CacheItemPoolInterface
{
    public function getItem(string $key): CacheItemInterface
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function getItems(array $keys = []): iterable
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function hasItem(string $key): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function clear(): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function deleteItem(string $key): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function deleteItems(array $keys): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function save(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }

    public function commit(): bool
    {
        throw new \RuntimeException('rate limiter storage unavailable');
    }
}
