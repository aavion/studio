<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiAdminOperationalControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testAdminOperationalEndpointsRequireApiKey(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/logs');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminOperationalEndpointsReturnBasicReadModels(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsadm');

        foreach ([
            '/api/v1/admin/backups',
            '/api/v1/admin/logs',
            '/api/v1/admin/operations',
            '/api/v1/admin/scheduler',
            '/api/v1/admin/statistics',
            '/api/v1/admin/themes',
        ] as $path) {
            $client->request('GET', $path, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful($path);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertArrayHasKey('data', $payload, $path);
        }
    }

    public function testAdminLogsListSourcesAndSourceEntries(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopslog');

        $client->request('GET', '/api/v1/admin/logs', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertGreaterThan(0, $payload['meta']['count']);
        self::assertSame('log_source', $payload['data'][0]['type']);

        $client->request('GET', '/api/v1/admin/logs/message?level=INFO&per_page=25', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('message', $payload['meta']['selected_source']);
        self::assertSame('INFO', $payload['meta']['filters']['level']);
    }

    public function testAdminOperationDetailAndContinuationReviewAreAvailable(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsrun');
        $store = self::getContainer()->get(LiveOperationRunStore::class);
        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('package.install.verify', [], 'Install package');
        $result = WorkflowResult::requiresReview(null, [
            Message::info(
                OperationMessageCode::OPERATION_ACTION_REQUIRED,
                OperationMessageKey::OPERATION_ACTION_REQUIRED,
                ['%operation%' => 'Install package'],
            ),
        ], [
            'live_operation_continuation' => [
                'operation' => 'package.install.apply',
                'payload' => ['install_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'package' => 'demo-module'],
                'label' => 'Install package',
            ],
        ]);
        $store->finish($run['operation_id'], false, $result->toArray());

        try {
            $client->request('GET', '/api/v1/admin/operations/'.$run['operation_id'], server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('operation_run', $payload['data']['type']);
            self::assertSame($run['operation_id'], $payload['data']['id']);
            self::assertSame('/api/v1/admin/operations/'.$run['operation_id'], $payload['links']['status']);
            self::assertSame('/api/v1/admin/operations/'.$run['operation_id'].'/continue', $payload['links']['continue']);
            self::assertSame('/api/v1/admin/operations/'.$run['operation_id'].'/continue', $payload['data']['links']['continue']);

            $client->request('POST', '/api/v1/admin/operations/'.$run['operation_id'].'/continue', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseStatusCodeSame(403);

            $plainWriteKey = $this->createPlainApiKey('apiopswrite', ApiKeyStatus::ReadWrite);
            $client->request('POST', '/api/v1/admin/operations/'.$run['operation_id'].'/continue', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainWriteKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('operation_continuation', $payload['data']['type']);
            self::assertSame('requires_confirmation', $payload['data']['attributes']['status']);
            self::assertArrayNotHasKey('payload', $payload['data']['attributes']);
            self::assertSame('/api/v1/admin/operations/'.$run['operation_id'], $payload['links']['status']);
            self::assertSame('/api/v1/admin/operations/'.$run['operation_id'].'/continue?confirm=true', $payload['links']['confirm']);
        } finally {
            @unlink(dirname($store->outputPath($run['operation_id'])).'/'.$run['operation_id'].'.json');
            @unlink($store->outputPath($run['operation_id']));
            @unlink($store->pidPath($run['operation_id']));
        }
    }

    public function testOpenApiIncludesAdminOperationalEndpoints(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());

        foreach (['/admin/backups', '/admin/logs', '/admin/logs/{log}', '/admin/operations', '/admin/operations/{operation_id}', '/admin/operations/{operation_id}/continue', '/admin/scheduler', '/admin/statistics', '/admin/themes'] as $path) {
            self::assertArrayHasKey($path, $payload['paths']);
        }
    }

    private function createPlainApiKey(string $prefix, ApiKeyStatus $status = ApiKeyStatus::ReadOnly): string
    {
        $user = $this->createUserWithLevel(AccessLevel::ADMIN, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '6a000000-0000-7000-8000-'.substr(md5($prefix.$status->value), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $user,
            $status,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);
        $entityManager->flush();

        return $plainKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
