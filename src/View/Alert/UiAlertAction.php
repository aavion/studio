<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class UiAlertAction
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        private string $label,
        private ?string $href = null,
        private ?string $target = null,
        private ?string $event = null,
        private array $detail = [],
    ) {
    }

    public static function link(string $label, string $href, ?string $target = null): self
    {
        return new self($label, href: $href, target: $target);
    }

    /**
     * @param array<string, mixed> $detail
     */
    public static function event(string $label, string $event, array $detail = []): self
    {
        return new self($label, event: $event, detail: $detail);
    }

    /**
     * @return array{label: string, href?: string, target?: string, event?: string, detail?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $action = ['label' => $this->label];

        if (null !== $this->href && '' !== trim($this->href)) {
            $action['href'] = $this->href;
        }

        if (null !== $this->target && '' !== trim($this->target)) {
            $action['target'] = $this->target;
        }

        if (null !== $this->event && '' !== trim($this->event)) {
            $action['event'] = $this->event;
        }

        if ([] !== $this->detail) {
            $action['detail'] = $this->detail;
        }

        return $action;
    }
}
