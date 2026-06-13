<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\View\Alert\UiAlert;
use PHPUnit\Framework\TestCase;

final class UiAlertTest extends TestCase
{
    public function testItSerializesNotificationCenterPayloadFields(): void
    {
        $alert = UiAlert::fromLevel('danger', 'Operation failed.', persistent: true, mode: 'persistent', id: 'operation:demo', actions: [
            ['label' => 'Show details', 'event' => 'operation-overlay:show', 'detail' => ['operation' => 'demo']],
        ], loading: true);

        self::assertSame([
            'message' => 'Operation failed.',
            'level' => 'error',
            'persistent' => true,
            'mode' => 'persistent',
            'loading' => true,
            'id' => 'operation:demo',
            'actions' => [
                ['label' => 'Show details', 'event' => 'operation-overlay:show', 'detail' => ['operation' => 'demo']],
            ],
        ], $alert->toArray());
    }
}
