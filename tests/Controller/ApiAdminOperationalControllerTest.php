<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminPermissionState;
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
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.logs' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('GET', '/api/v1/admin/logs', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertGreaterThan(0, $payload['meta']['count']);
            self::assertSame('log_source', $payload['data'][0]['type']);
            $sources = [];
            foreach ($payload['data'] as $resource) {
                $sources[$resource['id']] = $resource['attributes']['filters'];
            }
            self::assertSame(['level', 'q', 'match', 'time_window', 'limit', 'page'], $sources['application']);
            self::assertSame(['level', 'q', 'match', 'time_window', 'limit', 'page'], $sources['message']);
            self::assertSame(['q', 'match', 'time_window', 'audit_action', 'limit', 'page'], $sources['audit']);
            self::assertSame(['q', 'match', 'time_window', 'limit', 'page'], $sources['access']);
            self::assertSame(['level', 'q', 'match', 'time_window', 'audit_action', 'limit', 'page'], $sources['security_signal']);

            $client->request('GET', '/api/v1/admin/logs/message?level=INFO&limit=25', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('message', $payload['meta']['selected_source']);
            self::assertSame('INFO', $payload['meta']['filters']['level']);
            self::assertSame(25, $payload['meta']['filters']['limit']);
            self::assertArrayNotHasKey('per_page', $payload['meta']['filters']);
            self::assertArrayNotHasKey('per_page', $payload['meta']['pagination']);
            self::assertArrayNotHasKey('total_pages', $payload['meta']['pagination']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
            $this->removeSchedulerTasks();
        }
    }

    public function testAdminLogsFeatureReadOnlyHidesSensitiveSources(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopslogro', ApiKeyStatus::ReadWrite);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.logs' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('GET', '/api/v1/admin/logs', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            $sources = array_column($payload['data'], 'id');
            self::assertNotContains('audit', $sources);
            self::assertNotContains('security_signal', $sources);

            $client->request('GET', '/api/v1/admin/logs/audit', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseStatusCodeSame(403);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('admin.logs', $payload['error']['context']['feature']);
            self::assertSame('feature_read_only', $payload['error']['context']['reason']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
            $this->removeSchedulerTasks();
        }
    }

    public function testAdminOperationDetailAndContinuationReviewAreAvailable(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsrun');
        $store = self::getContainer()->get(LiveOperationRunStore::class);
        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $overrides = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $overrides);
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

        $overrides->save([
            'admin.operations' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
            'admin.packages' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

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

            $client->request('POST', '/api/v1/admin/operations/'.$run['operation_id'].'/continue?confirm=true', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainWriteKey,
            ]);

            self::assertResponseStatusCodeSame(403);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('admin.packages', $payload['error']['context']['feature']);
            self::assertSame('feature_read_only', $payload['error']['context']['reason']);
        } finally {
            $overrides->save($overrides->defaultOverrides(), 'test');
            @unlink(dirname($store->outputPath($run['operation_id'])).'/'.$run['operation_id'].'.json');
            @unlink($store->outputPath($run['operation_id']));
            @unlink($store->pidPath($run['operation_id']));
        }
    }

    public function testAdminOperationMaintenanceRequiresConfirmation(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsmaint', ApiKeyStatus::ReadWrite);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.operations' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('POST', '/api/v1/admin/operations/cleanup', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('operation_maintenance_review', $payload['data']['type']);
            self::assertSame('requires_confirmation', $payload['data']['attributes']['status']);
            self::assertSame('/api/v1/admin/operations/cleanup?confirm=true', $payload['links']['confirm']);

            $client->request('POST', '/api/v1/admin/operations/cleanup?confirm=true', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('operation_maintenance_result', $payload['data']['type']);
            self::assertSame('completed', $payload['data']['attributes']['status']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
        }
    }

    public function testAdminOperationsFeatureReadOnlyStillListsButRejectsConfirmedMaintenance(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsro', ApiKeyStatus::ReadWrite);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.operations' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('GET', '/api/v1/admin/operations', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/admin/operations/cleanup?confirm=true', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseStatusCodeSame(403);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('admin.operations', $payload['error']['context']['feature']);
            self::assertSame('feature_read_only', $payload['error']['context']['reason']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
        }
    }

    public function testAdminSchedulerTaskDetailAndPatchAreAvailable(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopssched', ApiKeyStatus::ReadWrite);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.scheduler' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('GET', '/api/v1/admin/scheduler/system.live_operation_cleanup', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('scheduler_task', $payload['data']['type']);
            self::assertSame('system.live_operation_cleanup', $payload['data']['id']);
            self::assertArrayHasKey('recent_runs', $payload['data']['relationships']);

            $client->request('PATCH', '/api/v1/admin/scheduler/system.live_operation_cleanup', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode([
                'enabled' => true,
                'cron_expression' => '*/10 * * * *',
            ], JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('active', $payload['data']['attributes']['status']);
            self::assertSame('*/10 * * * *', $payload['data']['attributes']['cron_expression']);

            $client->request('POST', '/api/v1/admin/scheduler/system.live_operation_cleanup/run', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('scheduler_run', $payload['data']['type']);
            self::assertSame('/api/v1/admin/scheduler/system.live_operation_cleanup', $payload['data']['links']['task']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
        }
    }

    public function testTrustedPackageDiscoverySchedulerRunUsesSchedulerAclRatherThanPackageAcl(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsschedpkg', ApiKeyStatus::ReadWrite);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.scheduler' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
            'admin.packages' => [
                'state' => AdminPermissionState::Denied->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('PATCH', '/api/v1/admin/scheduler/system.package_discovery', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode([
                'enabled' => true,
                'cron_expression' => '0 */6 * * *',
            ], JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/admin/scheduler/system.package_discovery/run', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('scheduler_run', $payload['data']['type']);
            self::assertSame('/api/v1/admin/scheduler/system.package_discovery', $payload['data']['links']['task']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
        }
    }

    public function testAdminSchedulerFeatureReadOnlyStillShowsTasksButRejectsMutations(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiopsschedro', ApiKeyStatus::ReadWrite);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);

        $store->save([
            'admin.scheduler' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $client->request('GET', '/api/v1/admin/scheduler', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful();

            $client->request('PATCH', '/api/v1/admin/scheduler/system.live_operation_cleanup', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode([
                'enabled' => false,
            ], JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertSame('admin.scheduler', $payload['error']['context']['feature']);
            self::assertSame('feature_read_only', $payload['error']['context']['reason']);
        } finally {
            $store->save($store->defaultOverrides(), 'test');
        }
    }

    public function testOpenApiIncludesAdminOperationalEndpoints(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());

        foreach (['/admin/backups', '/admin/logs', '/admin/logs/{log}', '/admin/operations', '/admin/operations/{action}', '/admin/operations/{operation_id}', '/admin/operations/{operation_id}/continue', '/admin/scheduler', '/admin/scheduler/{task_identifier}', '/admin/scheduler/{task_identifier}/run', '/admin/statistics', '/admin/themes'] as $path) {
            self::assertArrayHasKey($path, $payload['paths']);
        }

        self::assertSame(['backend-admin', 'backend-admin-backups'], $payload['paths']['/admin/backups']['get']['tags']);
        self::assertSame(['backend-admin', 'backend-admin-logs'], $payload['paths']['/admin/logs']['get']['tags']);
        self::assertSame(['backend-admin', 'backend-admin-operations'], $payload['paths']['/admin/operations']['get']['tags']);
        self::assertSame(['backend-admin', 'backend-admin-scheduler'], $payload['paths']['/admin/scheduler']['get']['tags']);
        self::assertSame(['backend-admin', 'backend-admin-statistics'], $payload['paths']['/admin/statistics']['get']['tags']);
        self::assertSame(['backend-admin', 'backend-admin-themes'], $payload['paths']['/admin/themes']['get']['tags']);
        self::assertSame(
            ['application', 'message', 'audit', 'access', 'security_signal'],
            $payload['paths']['/admin/logs/{log}']['get']['parameters'][0]['schema']['enum'],
        );
        self::assertContains([
            'name' => 'backend-admin-operations',
            'summary' => 'Backend Admin Operations',
            'description' => 'Administrative live-operation status, continuation, and maintenance resources.',
            'parent' => 'backend-admin',
            'kind' => 'nav',
        ], $payload['tags']);
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

    private function removeSchedulerTasks(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM scheduler_task_run WHERE task_identifier LIKE 'system.%'");
        $connection->executeStatement("DELETE FROM scheduler_task WHERE source = 'system'");
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
