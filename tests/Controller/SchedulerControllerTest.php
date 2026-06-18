<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Entity\SchedulerTask;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
use App\Security\AutoBan\TrustedApiKeyAutoBanBypass;
use App\Scheduler\SchedulerLockFactory;
use App\Scheduler\SchedulerSettings;
use App\Scheduler\SchedulerTaskDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SchedulerControllerTest extends WebTestCase
{
    public function testCronRunRequiresApiKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthorized', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    public function testCronRunAcceptsReadWriteBearerApiKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('completed', $payload['status']);
        self::assertSame('seedrw', $payload['auth']['api_key_prefix']);
    }

    public function testCronRunRejectsReadOnlyApiKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_only_key',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCronRunRejectsReadWriteApiKeyOwnedByNonAdmin(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        try {
            $connection->update('user_account', ['role' => 'user'], ['uid' => '00000000-0000-7000-8000-000000000201']);

            $client->request('GET', '/cron/run', server: [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            ]);

            self::assertResponseStatusCodeSame(401);
        } finally {
            $connection->update('user_account', ['role' => 'owner'], ['uid' => '00000000-0000-7000-8000-000000000201']);
        }
    }

    public function testCronRunRejectsUnknownJobIdentifier(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run?job=system.missing', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTrustedCronRunErrorsDoNotCreateSourceScoredSignals(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        $connection->executeStatement('DELETE FROM security_signal_event');
        $client->request('GET', '/cron/run?job=system.missing', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            'REMOTE_ADDR' => '198.51.100.61',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));

        $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, true, ConfigValueType::Boolean);
        try {
            $connection->executeStatement('DELETE FROM security_signal_event');
            $client->request('GET', '/cron/run?auth=test_seed_read_write_key&job=system.missing', server: [
                'REMOTE_ADDR' => '198.51.100.62',
            ]);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        } finally {
            $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, false, ConfigValueType::Boolean);
        }
    }

    public function testTrustedCronRunPayloadPatternsDoNotCreateSourceScoredSignals(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        $connection->executeStatement('DELETE FROM security_signal_event');
        $client->request('GET', '/cron/run?job=../etc/passwd', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            'REMOTE_ADDR' => '198.51.100.63',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));

        $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, true, ConfigValueType::Boolean);
        try {
            $connection->executeStatement('DELETE FROM security_signal_event');
            $client->request('GET', '/cron/run?auth=test_seed_read_write_key&job=../etc/passwd', server: [
                'REMOTE_ADDR' => '198.51.100.64',
            ]);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        } finally {
            $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, false, ConfigValueType::Boolean);
        }
    }

    public function testCronRunRejectsMalformedJobIdentifierBeforeRegistryLookup(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run?job='.str_repeat('x', 512), server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('invalid', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['job']);
    }

    public function testCronRunRejectsRevokedApiKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_revoked_key',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCronRunRejectsOversizedApiKeyWithoutReflectingIt(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('a', 512).'.secret',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(str_repeat('a', 16).'…', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['auth']);
    }

    public function testCronRunAutoBanGuardAllowsOnlyTrustedKeysBeforeSchedulerAuth(): void
    {
        $client = self::createClient();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set(AutoBanPolicy::ENABLED_KEY, AutoBanPolicy::SETUP_ENABLED, ConfigValueType::Boolean);

        try {
            $this->banIpBucketFor('/cron/run', '198.51.100.41');
            $client->request('GET', '/cron/run', server: $this->server('198.51.100.41') + [
                'HTTP_AUTHORIZATION' => 'Bearer invalid-scheduler-key',
            ]);
            self::assertResponseStatusCodeSame(403);

            $this->banIpBucketFor('/cron/run', '198.51.100.42');
            $trustedBypass = self::getContainer()->get(TrustedApiKeyAutoBanBypass::class);
            self::assertInstanceOf(TrustedApiKeyAutoBanBypass::class, $trustedBypass);
            self::assertTrue($trustedBypass->allows(
                Request::create('/cron/run', server: ['HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key']),
                allowPrefixlessBearer: true,
                allowSchedulerQuery: true,
            ));
            $client->request('GET', '/cron/run', server: $this->server('198.51.100.42') + [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            ]);
            self::assertResponseIsSuccessful();

            $this->banIpBucketFor('/cron/run', '198.51.100.43');
            $client->request('GET', '/cron/run', server: $this->server('198.51.100.43') + [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_only_key',
            ]);
            self::assertResponseStatusCodeSame(401);

            $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, true, ConfigValueType::Boolean);
            $this->banIpBucketFor('/cron/run?auth=test_seed_read_write_key', '198.51.100.44');
            $client->request('GET', '/cron/run?auth=test_seed_read_write_key', server: $this->server('198.51.100.44'));
            self::assertResponseIsSuccessful();

            $oversizedToken = str_repeat('a', 129);
            $this->banIpBucketFor('/cron/run?auth='.$oversizedToken, '198.51.100.45');
            $client->request('GET', '/cron/run?auth='.$oversizedToken, server: $this->server('198.51.100.45'));
            self::assertResponseStatusCodeSame(403);
        } finally {
            $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, false, ConfigValueType::Boolean);
        }
    }

    public function testCronRunGetAuthFallbackIsDisabledByDefault(): void
    {
        $client = self::createClient();
        self::getContainer()->get(Config::class)->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, false, ConfigValueType::Boolean);

        $client->request('GET', '/cron/run?auth=test_seed_read_write_key');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCronRunGetAuthFallbackWorksWhenEnabled(): void
    {
        $client = self::createClient();
        $config = self::getContainer()->get(Config::class);
        $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, true, ConfigValueType::Boolean);

        try {
            $client->request('GET', '/cron/run?auth=test_seed_read_write_key');

            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('completed', $payload['status']);
            self::assertSame('seedrw', $payload['auth']['api_key_prefix']);
        } finally {
            $config->set(SchedulerSettings::GET_AUTH_ENABLED_KEY, false, ConfigValueType::Boolean);
        }
    }

    public function testCronRunReportsLockContentionAsRetryableServiceUnavailable(): void
    {
        $client = self::createClient();
        $lock = self::getContainer()->get(SchedulerLockFactory::class)->acquire('run');
        self::assertNotNull($lock);

        try {
            $client->request('GET', '/cron/run', server: [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            ]);

            self::assertResponseStatusCodeSame(503);
            self::assertSame('60', $client->getResponse()->headers->get('Retry-After'));
            self::assertSame('locked', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['status']);
        } finally {
            $lock->release();
        }
    }

    public function testCronRunReturnsServerErrorWhenForcedTaskFails(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $definition = SchedulerTaskDefinition::command(
            'system.live_operation_cleanup',
            'admin.scheduler.tasks.live_operation_cleanup.label',
            'admin.scheduler.tasks.live_operation_cleanup.description',
            'operations:cleanup',
            '*/15 * * * *',
        );
        $task = $entityManager->find(SchedulerTask::class, 'system.live_operation_cleanup') ?? new SchedulerTask($definition);
        $task->syncDefinition($definition, new \DateTimeImmutable());
        $task->activate('not a cron');
        $entityManager->persist($task);
        $entityManager->flush();

        try {
            $client->request('GET', '/cron/run?job=system.live_operation_cleanup', server: [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            ]);

            self::assertResponseStatusCodeSame(500);
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('completed', $payload['status']);
            self::assertSame('failed', $payload['tasks'][0]['status']);
        } finally {
            $this->removeSchedulerTask($entityManager, 'system.live_operation_cleanup');
        }
    }

    public function testCronRunReturnsConflictWhenForcedTaskIsSkipped(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $definition = SchedulerTaskDefinition::command(
            'system.live_operation_cleanup',
            'admin.scheduler.tasks.live_operation_cleanup.label',
            'admin.scheduler.tasks.live_operation_cleanup.description',
            'operations:cleanup',
            '*/15 * * * *',
        );
        $task = $entityManager->find(SchedulerTask::class, 'system.live_operation_cleanup') ?? new SchedulerTask($definition);
        $task->syncDefinition($definition, new \DateTimeImmutable());
        $entityManager->persist($task);
        $entityManager->flush();

        try {
            $client->request('GET', '/cron/run?job=system.live_operation_cleanup', server: [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            ]);

            self::assertResponseStatusCodeSame(409);
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('completed', $payload['status']);
            self::assertSame('skipped', $payload['tasks'][0]['status']);
        } finally {
            $this->removeSchedulerTask($entityManager, 'system.live_operation_cleanup');
        }
    }

    private function removeSchedulerTask(EntityManagerInterface $entityManager, string $identifier): void
    {
        $connection = $entityManager->getConnection();
        $connection->delete('scheduler_task_run', ['task_identifier' => $identifier]);
        $connection->delete('scheduler_task', ['identifier' => $identifier]);
    }

    /**
     * @return array<string, string>
     */
    private function server(string $ip): array
    {
        return [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => 'SchedulerControllerTest',
            'HTTP_X_AUTO_BAN_TESTING' => '1',
        ];
    }

    private function banIpBucketFor(string $path, string $ip): void
    {
        $request = Request::create($path, server: $this->server($ip));
        $inspector = self::getContainer()->get(AbuseRequestInspector::class);
        self::assertInstanceOf(AbuseRequestInspector::class, $inspector);

        $subject = $inspector->inspect($request)['subjects']->first(AbuseSubjectType::IpBucket);
        self::assertInstanceOf(AbuseSubject::class, $subject);

        $autoBanSubject = AutoBanSubject::fromAbuseSubject($subject);
        self::assertInstanceOf(AutoBanSubject::class, $autoBanSubject);

        $store = self::getContainer()->get(AutoBanStore::class);
        self::assertInstanceOf(AutoBanStore::class, $store);
        $store->ban($autoBanSubject, 3600);
    }
}
