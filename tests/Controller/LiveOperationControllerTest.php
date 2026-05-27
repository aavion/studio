<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Workflow\WorkflowResult;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LiveOperationControllerTest extends WebTestCase
{
    public function testItReturnsTokenProtectedOperationStatus(): void
    {
        $client = self::createClient();
        $store = self::getContainer()->get(LiveOperationRunStore::class);

        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $store->appendEntry(
            $run['operation_id'],
            ActionLogEntry::pending('Clear cache')->start()->finish(ActionLogStatus::Success),
            1,
            1,
        );
        $store->finish($run['operation_id'], true, [
            'status' => 'success',
            'issues' => [[
                'level' => 'warning',
                'code' => 'E_OPERATION_FAILED',
                'translation_key' => 'message.operation.stale',
                'parameters' => ['%operation%' => 'Cache clear'],
                'context' => [],
            ]],
            'messages' => [],
        ]);

        $client->request('GET', '/api/live/operations/'.$run['operation_id'].'?token='.$run['token']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('success', $payload['status']);
        self::assertSame('Clear cache', $payload['entries'][0]['name']);
        self::assertSame('Operation "Cache clear" did not report progress in time and was marked as failed.', $payload['result']['issues'][0]['message']);

        $client->request('GET', '/api/live/operations/'.$run['operation_id'].'?token=invalid');

        self::assertResponseStatusCodeSame(404);
    }

    public function testItExposesReviewRequiredContinuationLinks(): void
    {
        $client = self::createClient();
        $store = self::getContainer()->get(LiveOperationRunStore::class);

        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('backend.cache_clear.dry_run', [], 'Cache clear dry-run');
        $result = WorkflowResult::requiresReview(null, [
            Message::info(
                MessageCode::OPERATION_ACTION_REQUIRED,
                MessageKey::OPERATION_ACTION_REQUIRED,
                ['%operation%' => 'Cache clear'],
            ),
        ], [
            'live_operation_continuation' => [
                'operation' => 'backend.cache_clear',
                'payload' => [],
                'label' => 'Cache clear',
            ],
        ]);
        $store->finish($run['operation_id'], false, $result->toArray());
        $continuedOperationId = null;

        try {
            $client->request('GET', '/api/live/operations/'.$run['operation_id'].'?token='.$run['token']);

            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('requires_review', $payload['status']);
            self::assertTrue($payload['can_continue']);
            self::assertStringContainsString('/api/live/operations/'.$run['operation_id'].'/continue', $payload['continue_url']);
            self::assertSame('Operation "Cache clear" needs confirmation before it can continue.', $payload['result']['issues'][0]['message']);

            $client->request('POST', $payload['continue_url']);

            self::assertResponseStatusCodeSame(202);
            $continued = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($continued['success']);
            self::assertSame('backend.cache_clear', $continued['value']['operation']);
            self::assertArrayHasKey('status_url', $continued['value']);
            $continuedOperationId = (string) $continued['value']['operation_id'];
        } finally {
            $this->waitForRunnerToFinish($store);
            @unlink(dirname($store->outputPath($run['operation_id'])).'/'.$run['operation_id'].'.json');
            @unlink($store->outputPath($run['operation_id']));
            @unlink($store->pidPath($run['operation_id']));
            if (null !== $continuedOperationId) {
                @unlink(dirname($store->outputPath($continuedOperationId)).'/'.$continuedOperationId.'.json');
                @unlink($store->outputPath($continuedOperationId));
                @unlink($store->pidPath($continuedOperationId));
            }
            $store->clearRunnerLock(staleOnly: false);
        }
    }

    private function waitForRunnerToFinish(LiveOperationRunStore $store): void
    {
        $deadline = microtime(true) + 3.0;

        do {
            if (null === $store->runnerLockStatus()) {
                return;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);
    }
}
