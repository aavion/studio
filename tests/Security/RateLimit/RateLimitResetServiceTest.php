<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Statistics\VisitorIdGenerator;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\RateLimit\RateLimitEnforcer;
use App\Security\RateLimit\RateLimitLimiterFactory;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use App\Security\RateLimit\RateLimitResetService;
use App\Security\RateLimit\RateLimitSubjectSelector;
use App\Security\SecurityMessageCode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class RateLimitResetServiceTest extends TestCase
{
    public function testLoginSuccessResetClearsVisitorAndIpLoginAttempts(): void
    {
        [$enforcer, $resets] = $this->services();
        $request = $this->request('/user/login', 'POST');

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($request)->isAllowed());
        }

        self::assertFalse($enforcer->check($request)->isAllowed());
        self::assertTrue($resets->resetLoginAttempts($request));
        self::assertTrue($enforcer->check($request)->isAllowed());
    }

    public function testLoginSuccessResetClearsSubmittedAccountLoginAttempts(): void
    {
        [$enforcer, $resets] = $this->services();

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login', 'POST', [
                'username' => 'shared-admin',
                'password' => 'wrong',
            ], [
                'REMOTE_ADDR' => '203.0.113.'.(20 + $i),
            ]))->isAllowed());
        }

        self::assertFalse($enforcer->check($this->request('/user/login', 'POST', [
            'username' => 'shared-admin',
            'password' => 'wrong',
        ], [
            'REMOTE_ADDR' => '203.0.113.90',
        ]))->isAllowed());

        self::assertTrue($resets->resetLoginAttempts($this->request('/user/login', 'POST', [
            'username' => 'shared-admin',
            'password' => 'correct',
        ], [
            'REMOTE_ADDR' => '203.0.113.91',
        ])));

        self::assertTrue($enforcer->check($this->request('/user/login', 'POST', [
            'username' => 'shared-admin',
            'password' => 'wrong',
        ], [
            'REMOTE_ADDR' => '203.0.113.92',
        ]))->isAllowed());
    }

    public function testLoginSuccessResetUsesActiveProfileDescriptor(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Strict->value, ConfigValueType::String);
        [$enforcer, $resets] = $this->services(config: $config);
        $request = $this->request('/user/login', 'POST');

        self::assertTrue($enforcer->check($request)->isAllowed());
        self::assertTrue($enforcer->check($request)->isAllowed());
        self::assertFalse($enforcer->check($request)->isAllowed());
        self::assertTrue($resets->resetLoginAttempts($request));
        self::assertTrue($enforcer->check($request)->isAllowed());
    }

    public function testCaptchaResetRequiresVerifiedProviderBackedSuccess(): void
    {
        [, $resets] = $this->services();
        $request = $this->request('/captcha/submit', 'POST');

        self::assertFalse($resets->resetVerifiedCaptchaFailure($request, 'none', true));
        self::assertFalse($resets->resetVerifiedCaptchaFailure($request, 'turnstile', false));
        self::assertTrue($resets->resetVerifiedCaptchaFailure($request, 'turnstile', true));
    }

    public function testCaptchaResetUsesActiveProfileDescriptor(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $cache = new ArrayAdapter();
        $factory = new RateLimitLimiterFactory($cache);
        [, $resets] = $this->services(config: $config, factory: $factory);
        $request = $this->request('/captcha/submit', 'POST');
        $catalogue = new RateLimitPolicyCatalogue();
        $descriptor = $catalogue->descriptor('captcha.failure', RateLimitProfile::Panic);
        self::assertNotNull($descriptor);
        $inspector = $this->inspector();
        $selector = new RateLimitSubjectSelector();
        $subjectKeys = $selector->subjectKeys($descriptor, $inspector->inspect($request)['subjects']);
        self::assertNotSame([], $subjectKeys);

        self::assertTrue($factory->consume($descriptor, $subjectKeys[0], 1));
        self::assertInstanceOf(\DateTimeImmutable::class, $factory->consume($descriptor, $subjectKeys[0], 1));
        self::assertTrue($resets->resetVerifiedCaptchaFailure($request, 'turnstile', true));
        self::assertTrue($factory->consume($descriptor, $subjectKeys[0], 1));
    }

    public function testOffModeDoesNotTouchResetStorage(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Off->value, ConfigValueType::String);
        [, $resets] = $this->services(config: $config, factory: new RateLimitLimiterFactory(new ResetFailingCachePool()));

        self::assertFalse($resets->resetLoginAttempts($this->request('/user/login', 'POST')));
        self::assertFalse($resets->resetVerifiedCaptchaFailure($this->request('/captcha/submit', 'POST'), 'turnstile', true));
    }

    public function testResetFailureReportsThroughMessageLayer(): void
    {
        $messages = new RecordingRateLimitMessageReporter();
        [, $resets] = $this->services(factory: new RateLimitLimiterFactory(new ResetFailingCachePool()), messages: $messages);

        self::assertFalse($resets->resetVerifiedCaptchaFailure($this->request('/captcha/submit', 'POST'), 'turnstile', true));
        self::assertSame(SecurityMessageCode::RATE_LIMIT_RESET_DEGRADED, $messages->records[0]['message']->code());
        self::assertSame('security.rate_limit.reset', $messages->records[0]['context']['operation']);
    }

    /**
     * @return array{0: RateLimitEnforcer, 1: RateLimitResetService}
     */
    private function services(?Config $config = null, ?RateLimitLimiterFactory $factory = null, ?RecordingRateLimitMessageReporter $messages = null): array
    {
        $inspector = $this->inspector();
        $catalogue = new RateLimitPolicyCatalogue();
        $selector = new RateLimitSubjectSelector();
        $factory ??= new RateLimitLimiterFactory(new ArrayAdapter());
        $config ??= new Config($this->connection());
        $messages ??= new RecordingRateLimitMessageReporter();

        return [
            new RateLimitEnforcer($inspector, $config, $catalogue, $selector, $factory, $messages),
            new RateLimitResetService($inspector, $config, $catalogue, $selector, $factory, $messages),
        ];
    }

    private function inspector(): AbuseRequestInspector
    {
        return new AbuseRequestInspector(
            new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), new TokenStorage(), 'test-secret'),
            new RequestIntentClassifier(),
            new ActionCostCatalogue(),
        );
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $server
     */
    private function request(string $path, string $method, array $parameters = [], array $server = []): Request
    {
        return Request::create($path, $method, $parameters, server: [
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_USER_AGENT' => 'RateLimitResetServiceTest',
            ...$server,
        ]);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}

final class ResetFailingCachePool implements CacheItemPoolInterface
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
