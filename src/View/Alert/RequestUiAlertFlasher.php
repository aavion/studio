<?php

declare(strict_types=1);

namespace App\View\Alert;

use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

final readonly class RequestUiAlertFlasher
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function flash(UiAlert $alert): bool
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession()) {
            return false;
        }

        try {
            $payload = ['_ui_alert' => true, ...$alert->toArray()];
            $request->getSession()->getFlashBag()->add((string) $payload['level'], $payload);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
