<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class UiAlertPresentation
{
    /**
     * @param list<UiAlertAction|array<string, mixed>> $actions
     */
    public function __construct(
        private UiAlertMode|string|null $mode = null,
        private ?string $title = null,
        private ?string $id = null,
        private array $actions = [],
        private ?bool $loading = null,
    ) {
    }

    /**
     * @param list<UiAlertAction|array<string, mixed>> $actions
     */
    public static function auto(?string $title = null, array $actions = [], ?string $id = null): self
    {
        return new self(UiAlertMode::Auto, $title, $id, $actions);
    }

    /**
     * @param list<UiAlertAction|array<string, mixed>> $actions
     */
    public static function hidden(?string $title = null, array $actions = [], ?string $id = null): self
    {
        return new self(UiAlertMode::Hidden, $title, $id, $actions);
    }

    /**
     * @param list<UiAlertAction|array<string, mixed>> $actions
     */
    public static function persistent(?string $title = null, array $actions = [], ?string $id = null): self
    {
        return new self(UiAlertMode::Persistent, $title, $id, $actions);
    }

    /**
     * @param list<UiAlertAction|array<string, mixed>> $actions
     */
    public static function loading(?string $title = null, array $actions = [], ?string $id = null): self
    {
        return new self(UiAlertMode::Persistent, $title, $id, $actions, true);
    }

    public function mode(): ?string
    {
        if ($this->mode instanceof UiAlertMode) {
            return $this->mode->value;
        }

        $mode = strtolower(trim((string) $this->mode));

        return match ($mode) {
            'auto', 'hidden', 'persistent' => $mode,
            default => null,
        };
    }

    public function title(): ?string
    {
        return null !== $this->title && '' !== trim($this->title) ? $this->title : null;
    }

    public function id(): ?string
    {
        return null !== $this->id && '' !== trim($this->id) ? $this->id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        return array_values(array_filter(array_map(
            static fn (UiAlertAction|array $action): ?array => $action instanceof UiAlertAction
                ? UiAlertAction::normalize($action->toArray())
                : UiAlertAction::normalize($action),
            $this->actions,
        )));
    }

    public function isLoading(): ?bool
    {
        return $this->loading;
    }
}
