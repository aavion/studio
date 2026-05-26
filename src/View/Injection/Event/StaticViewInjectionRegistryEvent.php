<?php

declare(strict_types=1);

namespace App\View\Injection\Event;

use App\Core\Event\PublicEventInterface;
use App\View\Injection\StaticViewInjection;
use Symfony\Contracts\EventDispatcher\Event;

final class StaticViewInjectionRegistryEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<StaticViewInjection> $injections
     */
    public function __construct(private array $injections = [])
    {
    }

    public function addInjection(StaticViewInjection $injection): void
    {
        $this->injections[] = $injection;
    }

    /**
     * @return list<StaticViewInjection>
     */
    public function injections(): array
    {
        return $this->injections;
    }
}
