<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDeliveryOptions;
use PHPUnit\Framework\TestCase;

final class UiAlertDeliveryTest extends TestCase
{
    public function testDeliveryModesExposeIntendedTransportSemantics(): void
    {
        $direct = UiAlertDelivery::Direct->toOptions();
        $queue = UiAlertDelivery::Queue->toOptions();
        $push = UiAlertDelivery::Push->toOptions();

        self::assertTrue($direct->flashes());
        self::assertFalse($direct->queues());
        self::assertFalse($direct->pushes());

        self::assertFalse($queue->flashes());
        self::assertTrue($queue->queues());
        self::assertTrue($queue->pushes());

        self::assertFalse($push->flashes());
        self::assertFalse($push->queues());
        self::assertTrue($push->pushes());
    }

    public function testDeliveryOptionsKeepQueueAsDefault(): void
    {
        $options = new UiAlertDeliveryOptions();

        self::assertSame(UiAlertDelivery::Queue, $options->delivery());
        self::assertTrue($options->queues());
        self::assertTrue($options->pushes());
    }
}
