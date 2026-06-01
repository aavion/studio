<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Scheduler\SchedulerLockFactory;
use App\Scheduler\SchedulerSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
            $connection->update('user_account', ['role' => 'user'], ['uid' => '00000000-0000-0000-0000-000000000201']);

            $client->request('GET', '/cron/run', server: [
                'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_write_key',
            ]);

            self::assertResponseStatusCodeSame(401);
        } finally {
            $connection->update('user_account', ['role' => 'owner'], ['uid' => '00000000-0000-0000-0000-000000000201']);
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
}
