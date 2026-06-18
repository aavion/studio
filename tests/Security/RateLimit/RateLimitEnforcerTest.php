<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Api\Http\ApiRequestContext;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\RateLimit\RateLimitEnforcer;
use App\Security\RateLimit\RateLimitEnforcementStage;
use App\Security\RateLimit\RateLimitLimiterFactory;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use App\Security\RateLimit\RateLimitSubjectSelector;
use App\Security\ApiKeyStatus;
use App\Security\SecurityMessageCode;
use App\Security\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
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
        $messages = new RecordingRateLimitMessageReporter();
        $result = $this->enforcer(cachePool: new FailingCachePool(), messages: $messages)->check($this->request('/home'));

        self::assertTrue($result->isAllowed());
        self::assertTrue($result->storageDegraded());
        self::assertSame(SecurityMessageCode::RATE_LIMIT_STORAGE_DEGRADED, $messages->records[0]['message']->code());
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

    public function testLoginFormRendersDoNotSpendLoginWorkflowBudget(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 6; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login'))->isAllowed());
        }

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login', 'POST'))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/login', 'POST'));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.login', $result->diagnosticsLabel());
    }

    public function testRecoveryLoginBypassUsesDedicatedBucketWithoutWebsiteBudget(): void
    {
        $enforcer = $this->enforcer();

        self::assertTrue($enforcer->check($this->request('/user/login?bypass=1'), RateLimitEnforcementStage::Ordinary)->isAllowed());
        self::assertTrue($enforcer->check($this->request('/user/login?bypass=1'), RateLimitEnforcementStage::Ordinary)->isAllowed());

        $result = $enforcer->check($this->request('/user/login?bypass=1'), RateLimitEnforcementStage::Ordinary);

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.recovery_login', $result->diagnosticsLabel());
    }

    public function testPanicRecoveryLoginRenderAndSubmitFitBudgets(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);

        self::assertTrue($enforcer->check($this->request('/user/login?bypass=1'), RateLimitEnforcementStage::Ordinary)->isAllowed());
        self::assertTrue($enforcer->check($this->request('/user/login', 'POST', [
            'username' => 'recovery-owner',
            'password' => 'wrong',
        ]), RateLimitEnforcementStage::AuthenticationFailure)->isAllowed());
    }

    public function testBypassLoginPostUsesLoginFailureBudget(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login?bypass=1', 'POST', [
                'username' => 'manual-bypass',
                'password' => 'wrong',
            ]), RateLimitEnforcementStage::AuthenticationFailure)->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/login?bypass=1', 'POST', [
            'username' => 'manual-bypass',
            'password' => 'wrong',
        ]), RateLimitEnforcementStage::AuthenticationFailure);

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.login', $result->diagnosticsLabel());
    }

    public function testLoginAttemptsShareSubmittedAccountAcrossVisitors(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login', 'POST', [
                'username' => 'shared-admin',
                'password' => 'wrong',
            ], $this->server('203.0.113.'.(20 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/login', 'POST', [
            'username' => 'shared-admin',
            'password' => 'wrong',
        ], $this->server('203.0.113.99')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.login', $result->diagnosticsLabel());
    }

    public function testLocalLoginExhaustionDoesNotSpendSubmittedAccountBuckets(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login', 'POST', [
                'username' => 'local-block',
                'password' => 'wrong',
            ]))->isAllowed());
        }

        for ($i = 0; $i < 3; ++$i) {
            self::assertFalse($enforcer->check($this->request('/user/login', 'POST', [
                'username' => 'victim-account',
                'password' => 'wrong',
            ]))->isAllowed());
        }

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/login', 'POST', [
                'username' => 'victim-account',
                'password' => 'wrong',
            ], $this->server('203.0.113.'.(60 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/login', 'POST', [
            'username' => 'victim-account',
            'password' => 'wrong',
        ], $this->server('203.0.113.90')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.login', $result->diagnosticsLabel());
    }

    public function testPasswordResetAttemptsShareSubmittedEmailAcrossVisitors(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/reset-password', 'POST', [
                'email' => 'target@example.test',
            ], $this->server('203.0.113.'.(40 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/reset-password', 'POST', [
            'email' => 'TARGET@EXAMPLE.TEST',
        ], $this->server('203.0.113.100')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.password_reset', $result->diagnosticsLabel());
    }

    public function testPasswordResetTokenAttemptsShareSubmittedTokenAcrossVisitors(): void
    {
        $enforcer = $this->enforcer();
        $token = str_repeat('a', 64);

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/reset-password/'.$token, 'POST', [], $this->server('203.0.113.'.(100 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/reset-password/'.$token, 'POST', [], $this->server('203.0.113.110')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.password_reset', $result->diagnosticsLabel());
    }

    public function testLocalPasswordResetExhaustionDoesNotSpendSubmittedEmailBuckets(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/reset-password', 'POST', [
                'email' => 'local-block@example.test',
            ]))->isAllowed());
        }

        for ($i = 0; $i < 2; ++$i) {
            self::assertFalse($enforcer->check($this->request('/user/reset-password', 'POST', [
                'email' => 'victim@example.test',
            ]))->isAllowed());
        }

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/reset-password', 'POST', [
                'email' => 'victim@example.test',
            ], $this->server('203.0.113.'.(70 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/reset-password', 'POST', [
            'email' => 'VICTIM@EXAMPLE.TEST',
        ], $this->server('203.0.113.95')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.password_reset', $result->diagnosticsLabel());
    }

    public function testLocalRegistrationExhaustionDoesNotSpendSubmittedEmailBuckets(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/register', 'POST', [
                'email' => 'local-block@example.test',
            ]))->isAllowed());
        }

        for ($i = 0; $i < 2; ++$i) {
            self::assertFalse($enforcer->check($this->request('/user/register', 'POST', [
                'email' => 'victim-registration@example.test',
            ]))->isAllowed());
        }

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/register', 'POST', [
                'email' => 'victim-registration@example.test',
            ], $this->server('203.0.113.'.(80 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/register', 'POST', [
            'email' => 'VICTIM-REGISTRATION@EXAMPLE.TEST',
        ], $this->server('203.0.113.96')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.registration', $result->diagnosticsLabel());
    }

    public function testInvitationTokenAttemptsShareSubmittedTokenAcrossVisitors(): void
    {
        $enforcer = $this->enforcer();
        $token = str_repeat('b', 64);

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/invitation/'.$token, 'POST', [], $this->server('203.0.113.'.(120 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/invitation/'.$token, 'POST', [], $this->server('203.0.113.130')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.registration', $result->diagnosticsLabel());
    }

    public function testWebsiteExhaustionDoesNotSpendRegistrationAccountBucket(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($enforcer->check($this->request('/home'))->isAllowed());
        }

        $blocked = $enforcer->check($this->request('/user/register', 'POST', [
            'email' => 'global-victim@example.test',
        ]));

        self::assertFalse($blocked->isAllowed());
        self::assertSame('security.rate.website_burst', $blocked->diagnosticsLabel());

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/register', 'POST', [
                'email' => 'global-victim@example.test',
            ], $this->server('203.0.113.'.(140 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/register', 'POST', [
            'email' => 'GLOBAL-VICTIM@EXAMPLE.TEST',
        ], $this->server('203.0.113.150')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.registration', $result->diagnosticsLabel());
    }

    public function testWebsiteExhaustionDoesNotSpendPasswordResetAccountBucket(): void
    {
        $enforcer = $this->enforcer();

        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($enforcer->check($this->request('/home'))->isAllowed());
        }

        $blocked = $enforcer->check($this->request('/user/reset-password', 'POST', [
            'email' => 'global-reset@example.test',
        ]));

        self::assertFalse($blocked->isAllowed());
        self::assertSame('security.rate.website_burst', $blocked->diagnosticsLabel());

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($enforcer->check($this->request('/user/reset-password', 'POST', [
                'email' => 'global-reset@example.test',
            ], $this->server('203.0.113.'.(160 + $i))))->isAllowed());
        }

        $result = $enforcer->check($this->request('/user/reset-password', 'POST', [
            'email' => 'GLOBAL-RESET@EXAMPLE.TEST',
        ], $this->server('203.0.113.170')));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.password_reset', $result->diagnosticsLabel());
    }

    public function testOwnerIsExemptFromOrdinaryRateLimitRejection(): void
    {
        $tokenStorage = $this->tokenStorage(UserRole::Owner);
        $enforcer = $this->enforcer(tokenStorage: $tokenStorage);

        for ($i = 0; $i < 40; ++$i) {
            self::assertTrue($enforcer->check($this->request('/home'))->isAllowed());
        }
    }

    public function testOwnerApiContextDoesNotBypassAuthenticationFailureBudgets(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);
        $result = null;

        for ($i = 0; $i < 8; ++$i) {
            $request = $this->request('/api/v1/admin/settings/general', 'PATCH');
            $this->apiContext(ApiKeyStatus::ReadWrite, UserRole::Owner)->attachTo($request);
            $result = $enforcer->check($request, RateLimitEnforcementStage::AuthenticationFailure);
        }

        self::assertNotNull($result);
        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.admin_mutation', $result->diagnosticsLabel());
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

    public function testSchedulerRequestsAreNotOwnerExempt(): void
    {
        $tokenStorage = $this->tokenStorage(UserRole::Owner);
        $enforcer = $this->enforcer(tokenStorage: $tokenStorage);

        self::assertTrue($enforcer->check($this->request('/cron/run', 'POST'))->isAllowed());

        $result = $enforcer->check($this->request('/cron/run', 'POST'));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.scheduler', $result->diagnosticsLabel());
    }

    public function testSchedulerIntervalOnlyAppliesToCronRun(): void
    {
        $enforcer = $this->enforcer();

        self::assertTrue($enforcer->check($this->request('/cron/not-found', 'GET'))->isAllowed());
        self::assertTrue($enforcer->check($this->request('/cron/run', 'GET'))->isAllowed());

        $result = $enforcer->check($this->request('/cron/run', 'GET'));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.scheduler', $result->diagnosticsLabel());
    }

    public function testStrictSchedulerIntervalRejectsSecondRunWithinFifteenMinutes(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Strict->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);

        self::assertTrue($enforcer->check($this->request('/cron/run', 'POST'))->isAllowed());

        $result = $enforcer->check($this->request('/cron/run', 'POST'));

        self::assertFalse($result->isAllowed());
        self::assertGreaterThanOrEqual(1, $result->retryAfterSeconds() ?? 0);
    }

    public function testSchedulerIntervalUsesStableSubmittedCredentialAcrossVisitorChanges(): void
    {
        $enforcer = $this->enforcer();

        self::assertTrue($enforcer->check($this->request('/cron/run?auth=scheduler-token', 'GET', [], [
            'REMOTE_ADDR' => '203.0.113.77',
            'HTTP_USER_AGENT' => 'SchedulerProbe/1',
        ]))->isAllowed());

        $result = $enforcer->check($this->request('/cron/run?auth=scheduler-token', 'GET', [], [
            'REMOTE_ADDR' => '203.0.113.77',
            'HTTP_USER_AGENT' => 'SchedulerProbe/2',
        ]));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.scheduler', $result->diagnosticsLabel());
    }

    public function testSchedulerIntervalKeepsIpAnchorForAuthenticatedUsersWithRotatingCredentials(): void
    {
        $tokenStorage = $this->tokenStorage(UserRole::User);
        $enforcer = $this->enforcer(tokenStorage: $tokenStorage);

        self::assertTrue($enforcer->check($this->request('/cron/run?auth=scheduler-token-a', 'GET', [], [
            'REMOTE_ADDR' => '203.0.113.78',
            'HTTP_USER_AGENT' => 'SchedulerProbe/1',
        ]))->isAllowed());

        $result = $enforcer->check($this->request('/cron/run?auth=scheduler-token-b', 'GET', [], [
            'REMOTE_ADDR' => '203.0.113.78',
            'HTTP_USER_AGENT' => 'SchedulerProbe/2',
        ]));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.scheduler', $result->diagnosticsLabel());
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

    public function testAdminAuthFailureBudgetUsesIpSecondaryAcrossVisitorChanges(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);
        $result = null;

        for ($i = 0; $i < 8; ++$i) {
            $result = $enforcer->check($this->request('/api/v1/admin/settings/general', 'PATCH', [], [
                'REMOTE_ADDR' => '203.0.113.88',
                'HTTP_USER_AGENT' => 'AdminProbe/'.$i,
                'HTTP_AUTHORIZATION' => sprintf('Bearer admin%02d.invalid-secret', $i),
            ]), RateLimitEnforcementStage::AuthenticationFailure);
        }

        self::assertNotNull($result);
        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.admin_mutation', $result->diagnosticsLabel());
    }

    public function testReadOnlyOwnerApiKeyMutationsAreNotOwnerExempt(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);
        $result = null;

        for ($i = 0; $i < 16; ++$i) {
            $request = $this->request('/api/v1/content/items', 'POST');
            $this->apiContext(ApiKeyStatus::ReadOnly, UserRole::Owner)->attachTo($request);
            $result = $enforcer->check($request, RateLimitEnforcementStage::Ordinary);
        }

        self::assertNotNull($result);
        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.api_write', $result->diagnosticsLabel());
    }

    public function testReadOnlyOwnerApiKeyUnsafePreflightsAreNotOwnerExempt(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);
        $result = null;

        for ($i = 0; $i < 8; ++$i) {
            $request = $this->request('/api/v1/admin/settings/general', 'OPTIONS', server: [
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_AUTHORIZATION' => 'Bearer read-only-owner',
            ]);
            $this->apiContext(ApiKeyStatus::ReadOnly, UserRole::Owner)->attachTo($request);
            $result = $enforcer->check($request, RateLimitEnforcementStage::Ordinary);
        }

        self::assertNotNull($result);
        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.admin_mutation', $result->diagnosticsLabel());
    }

    public function testReadOnlyOwnerApiKeySafePreflightsRemainOwnerExempt(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);

        for ($i = 0; $i < 20; ++$i) {
            $request = $this->request('/api/v1/status', 'OPTIONS', server: [
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_AUTHORIZATION' => 'Bearer read-only-owner',
            ]);
            $this->apiContext(ApiKeyStatus::ReadOnly, UserRole::Owner)->attachTo($request);
            self::assertTrue($enforcer->check($request, RateLimitEnforcementStage::Ordinary)->isAllowed());
        }
    }

    public function testCredentialedNonBearerPreflightsSpendRequestedMethodBucket(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);
        $result = null;

        for ($i = 0; $i < 8; ++$i) {
            $result = $enforcer->check($this->request('/api/v1/admin/settings/general', 'OPTIONS', server: [
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_AUTHORIZATION' => 'Basic credential-probe',
            ]), RateLimitEnforcementStage::Ordinary);
        }

        self::assertNotNull($result);
        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.admin_mutation', $result->diagnosticsLabel());
    }

    public function testReadWriteOwnerApiKeyMutationsRemainOwnerExempt(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);

        for ($i = 0; $i < 20; ++$i) {
            $request = $this->request('/api/v1/content/items', 'POST');
            $this->apiContext(ApiKeyStatus::ReadWrite, UserRole::Owner)->attachTo($request);
            self::assertTrue($enforcer->check($request, RateLimitEnforcementStage::Ordinary)->isAllowed());
        }
    }

    public function testPanicAdminMutationConsumesWebsiteBucketWithoutStorageDegradation(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $messages = new RecordingRateLimitMessageReporter();
        $enforcer = $this->enforcer(config: $config, messages: $messages);

        self::assertTrue($enforcer->check($this->request('/admin/settings/security', 'POST'))->isAllowed());
        self::assertTrue($enforcer->check($this->request('/admin/settings/security', 'POST'))->isAllowed());

        $result = $enforcer->check($this->request('/admin/settings/security', 'POST'));

        self::assertFalse($result->isAllowed());
        self::assertFalse($result->storageDegraded());
        self::assertSame('security.rate.website_burst', $result->diagnosticsLabel());
        self::assertSame([], $messages->records);
    }

    public function testSetupWizardPostsDoNotSpendSetupApplyBudget(): void
    {
        $config = new Config($this->connection());
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
        $enforcer = $this->enforcer(config: $config);

        for ($i = 0; $i < 12; ++$i) {
            self::assertTrue($enforcer->check($this->request('/setup/database', 'POST', [
                '_setup_action' => 'test_database',
            ]))->isAllowed());
        }

        self::assertTrue($enforcer->check($this->request('/setup/review', 'POST', [
            '_setup_action' => 'apply',
        ]))->isAllowed());
        self::assertTrue($enforcer->check($this->request('/setup/review', 'POST', [
            '_setup_action' => 'apply',
        ]))->isAllowed());

        $result = $enforcer->check($this->request('/setup/review', 'POST', [
            '_setup_action' => 'apply',
        ]));

        self::assertFalse($result->isAllowed());
        self::assertSame('security.rate.setup_apply', $result->diagnosticsLabel());
    }

    public function testRepresentativeRequestPathsReachExpectedBuckets(): void
    {
        $cases = [
            ['/user/register', 'POST', ['email' => 'registration@example.test'], 'security.rate.registration', 4],
            ['/user/reset-password', 'POST', ['email' => 'reset@example.test'], 'security.rate.password_reset', 4],
            ['/contact', 'POST', [], 'security.rate.website_form', 3],
            ['/api/v1/content/items', 'GET', [], 'security.rate.api_public_read', 31],
            ['/api/v1/content/items', 'POST', [], 'security.rate.api_write', 16],
            ['/cron/run', 'POST', [], 'security.rate.scheduler', 2],
            ['/setup/review', 'POST', ['_setup_action' => 'apply'], 'security.rate.setup_apply', 3],
            ['/admin/settings/security', 'POST', [], 'security.rate.website_burst', 8],
            ['/admin/packages/upload', 'POST', [], 'security.rate.website_burst', 6],
            ['/admin/logs/download', 'GET', [], 'security.rate.website_burst', 8],
        ];

        foreach ($cases as [$path, $method, $parameters, $label, $attempts]) {
            $config = new Config($this->connection());
            $config->set(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Panic->value, ConfigValueType::String);
            $enforcer = $this->enforcer(config: $config);
            $result = null;

            for ($i = 0; $i < $attempts; ++$i) {
                $result = $enforcer->check($this->request($path, $method, $parameters));
            }

            self::assertNotNull($result);
            self::assertFalse($result->isAllowed(), $path);
            self::assertSame($label, $result->diagnosticsLabel(), $path);
        }
    }

    private function enforcer(?Config $config = null, ?TokenStorage $tokenStorage = null, ?CacheItemPoolInterface $cachePool = null, ?RecordingRateLimitMessageReporter $messages = null): RateLimitEnforcer
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
            $messages ?? new RecordingRateLimitMessageReporter(),
        );
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $server
     */
    private function request(string $path, string $method = 'GET', array $parameters = [], array $server = []): Request
    {
        return Request::create($path, $method, $parameters, server: [
            ...$this->server('203.0.113.9'),
            ...$server,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function server(string $ip): array
    {
        return [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => 'RateLimitEnforcerTest-'.$ip,
        ];
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

    private function apiContext(ApiKeyStatus $status, UserRole $role): ApiRequestContext
    {
        $user = new UserAccount(
            '99999999-0000-7000-8000-000000000101',
            'rate_limit_api_'.$role->value,
            'rate-limit-api-'.$role->value.'@example.test',
            'hash',
            role: $role,
        );

        return ApiRequestContext::fromApiKey(new ApiKey(
            '99999999-0000-7000-8000-000000000201',
            'rlapi',
            str_repeat('a', 64),
            'encrypted',
            $user,
            $status,
        ));
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
