<?php

declare(strict_types=1);

namespace App\Core\Event;

final readonly class PublicEventHookRegistry
{
    /**
     * @param iterable<EventHookDescriptorProviderInterface> $providers
     */
    public function __construct(private iterable $providers = [])
    {
    }

    /**
     * @return list<EventHookDescriptor>
     */
    public function hooks(): array
    {
        $providers = [];

        foreach ($this->providers as $provider) {
            $providers[] = $provider;
        }

        if ([] === $providers) {
            $providers[] = new CoreEventHookProvider();
        }

        $hooks = [];

        foreach ($providers as $provider) {
            foreach ($provider->hooks() as $hook) {
                $hooks[$hook->eventClass()] = $hook;
            }
        }

        return array_values($hooks);
    }

    /**
     * @return array<class-string<PublicEventInterface>, EventHookDescriptor>
     */
    public function byEventClass(): array
    {
        $hooks = [];

        foreach ($this->hooks() as $hook) {
            $hooks[$hook->eventClass()] = $hook;
        }

        return $hooks;
    }
}
