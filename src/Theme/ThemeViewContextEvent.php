<?php

declare(strict_types=1);

namespace App\Theme;

use Symfony\Contracts\EventDispatcher\Event;

final class ThemeViewContextEvent extends Event
{
    public const NAME = 'studio.theme.view_context';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(private array $context)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): void
    {
        $this->context = array_replace_recursive($this->context, $values);
    }
}
