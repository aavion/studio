<?php

declare(strict_types=1);

namespace App\Core\DryRun;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use InvalidArgumentException;

final readonly class DryRunPlan
{
    /**
     * @param list<DryRunAction> $actions
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $name,
        private array $actions = [],
        private array $context = [],
    ) {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('Dry-run plan name must not be empty.');
        }

        foreach ($actions as $action) {
            if (!$action instanceof DryRunAction) {
                throw new InvalidArgumentException('Dry-run plans may only contain DryRunAction instances.');
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function create(string $name, array $context = []): self
    {
        return new self($name, context: $context);
    }

    public function add(DryRunAction $action): self
    {
        return new self($this->name, [...$this->actions, $action], $this->context);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<DryRunAction>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function isEmpty(): bool
    {
        return [] === $this->actions;
    }

    public function highestRisk(): ?DryRunRisk
    {
        $highest = null;

        foreach ($this->actions as $action) {
            if (null === $highest || $action->risk()->value > $highest->value) {
                $highest = $action->risk();
            }
        }

        return $highest;
    }

    /**
     * @return list<string>
     */
    public function affectedPaths(): array
    {
        $paths = [];

        foreach ($this->actions as $action) {
            array_push($paths, ...$action->paths());
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<string, int>
     */
    public function actionCounts(): array
    {
        $counts = [];

        foreach ($this->actions as $action) {
            $counts[$action->type()] = ($counts[$action->type()] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function hasDiffs(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->hasDiffs()) {
                return true;
            }
        }

        return false;
    }

    public function toActionLog(): ActionLog
    {
        $log = ActionLog::create();

        foreach ($this->actions as $action) {
            $log = $log->add(ActionLogEntry::pending($action->label(), [
                'dry_run' => true,
                'type' => $action->type(),
                'risk' => $action->risk()->label(),
                'paths' => $action->paths(),
                'diffs' => array_map(static fn (DryRunDiff $diff): array => $diff->toArray(), $action->diffs()),
                ...$action->context(),
            ])->finish(ActionLogStatus::Skipped));
        }

        return $log;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{name: string, actions: list<array{type: string, label: string, risk: string, paths: list<string>, diffs: list<array{type: string, label: string, payload: array<string, mixed>}>, context: array<string, mixed>}>, action_counts: array<string, int>, affected_paths: list<string>, highest_risk: string|null, has_diffs: bool, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'actions' => array_map(static fn (DryRunAction $action): array => $action->toArray(), $this->actions),
            'action_counts' => $this->actionCounts(),
            'affected_paths' => $this->affectedPaths(),
            'highest_risk' => $this->highestRisk()?->label(),
            'has_diffs' => $this->hasDiffs(),
            'context' => $this->context,
        ];
    }
}
