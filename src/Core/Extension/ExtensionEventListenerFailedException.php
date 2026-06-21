<?php

declare(strict_types=1);

namespace App\Core\Extension;

use RuntimeException;
use Throwable;

final class ExtensionEventListenerFailedException extends RuntimeException
{
    public function __construct(private readonly ExtensionEventListenerRegistration $registration, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function registration(): ExtensionEventListenerRegistration
    {
        return $this->registration;
    }
}
