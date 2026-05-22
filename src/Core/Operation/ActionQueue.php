<?php

declare(strict_types=1);

namespace App\Core\Operation;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, OperationActionInterface>
 */
final readonly class ActionQueue implements Countable, IteratorAggregate
{
    /**
     * @param list<OperationActionInterface> $actions
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $name,
        private array $actions = [],
        private bool $stopOnFailure = true,
        private array $context = [],
    ) {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('Action queue name must not be empty.');
        }

        foreach ($actions as $action) {
            if (!$action instanceof OperationActionInterface) {
                throw new InvalidArgumentException('Action queue items must implement OperationActionInterface.');
            }
        }
    }

    /**
     * @param list<OperationActionInterface> $actions
     * @param array<string, mixed> $context
     */
    public static function create(string $name, array $actions = [], bool $stopOnFailure = true, array $context = []): self
    {
        return new self($name, $actions, $stopOnFailure, $context);
    }

    public function add(OperationActionInterface $action): self
    {
        return new self($this->name, [...$this->actions, $action], $this->stopOnFailure, $this->context);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<OperationActionInterface>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function isEmpty(): bool
    {
        return [] === $this->actions;
    }

    public function count(): int
    {
        return count($this->actions);
    }

    public function stopOnFailure(): bool
    {
        return $this->stopOnFailure;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return Traversable<int, OperationActionInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->actions);
    }
}
