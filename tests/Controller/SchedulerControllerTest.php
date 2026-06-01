<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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

    public function testCronRunAcceptsReadOnlyBearerApiKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_only_key',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('completed', $payload['status']);
        self::assertSame('seedro', $payload['auth']['api_key_prefix']);
    }

    public function testCronRunRejectsUnknownJobIdentifier(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cron/run?job=system.missing', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test_seed_read_only_key',
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
