<?php

declare(strict_types=1);

namespace App\View\Injection\Event;

use App\Core\Event\PublicEventInterface;
use App\View\Injection\DynamicViewInjection;
use Symfony\Contracts\EventDispatcher\Event;

final class DynamicViewInjectionRegistryEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<DynamicViewInjection> $injections
     */
    public function __construct(private array $injections = [])
    {
    }

    public function addInjection(DynamicViewInjection $injection): void
    {
        $this->injections[] = $injection;
    }

    /**
     * @return list<DynamicViewInjection>
     */
    public function injections(): array
    {
        return $this->injections;
    }
}
