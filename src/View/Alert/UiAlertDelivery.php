<?php

declare(strict_types=1);

namespace App\View\Alert;

enum UiAlertDelivery
{
    case Direct;
    case Queue;

    public function toOptions(): UiAlertDeliveryOptions
    {
        return match ($this) {
            self::Direct => UiAlertDeliveryOptions::direct(),
            self::Queue => UiAlertDeliveryOptions::queued(),
        };
    }
}
