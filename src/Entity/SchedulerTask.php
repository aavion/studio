<?php

declare(strict_types=1);

namespace App\Entity;

use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskStatus;
use App\Scheduler\SchedulerTaskType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduler_task')]
#[ORM\Index(name: 'idx_scheduler_task_status_due', columns: ['status', 'next_due_at'])]
#[ORM\Index(name: 'idx_scheduler_task_source', columns: ['source'])]
class SchedulerTask
{
    #[ORM\Id]
    #[ORM\Column(length: 160)]
    private string $identifier;

    #[ORM\Column(length: 160)]
    private string $labelKey;

    #[ORM\Column(length: 160)]
    private string $descriptionKey;

    #[ORM\Column(length: 160)]
    private string $source;

    #[ORM\Column(enumType: SchedulerTaskType::class)]
    private SchedulerTaskType $type;

    #[ORM\Column(length: 255)]
    private string $target;

    #[ORM\Column(length: 120)]
    private string $cronExpression;

    #[ORM\Column(length: 120)]
    private string $defaultCronExpression;

    #[ORM\Column(enumType: SchedulerTaskStatus::class)]
    private SchedulerTaskStatus $status = SchedulerTaskStatus::Inactive;

    #[ORM\Column]
    private bool $trusted = false;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $nextDueAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column]
    private int $failureCount = 0;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    public function __construct(SchedulerTaskDefinition $definition, ?DateTimeImmutable $now = null)
    {
        $now ??= new DateTimeImmutable();
        $this->identifier = $definition->identifier();
        $this->labelKey = $definition->labelKey();
        $this->descriptionKey = $definition->descriptionKey();
        $this->source = $definition->source();
        $this->type = $definition->type();
        $this->target = $definition->target();
        $this->cronExpression = $definition->defaultCronExpression();
        $this->defaultCronExpression = $definition->defaultCronExpression();
        $this->trusted = $definition->trusted();
        $this->metadata = $definition->metadata();
        $this->modifiedAt = $now;
    }

    public function syncDefinition(SchedulerTaskDefinition $definition, DateTimeImmutable $now): bool
    {
        $usesPreviousDefaultCron = $this->cronExpression === $this->defaultCronExpression;
        $changed = $this->labelKey !== $definition->labelKey()
            || $this->descriptionKey !== $definition->descriptionKey()
            || $this->source !== $definition->source()
            || $this->type !== $definition->type()
            || $this->target !== $definition->target()
            || $this->defaultCronExpression !== $definition->defaultCronExpression()
            || $this->trusted !== $definition->trusted()
            || $this->metadata !== $definition->metadata();

        if (!$changed) {
            return false;
        }

        $this->labelKey = $definition->labelKey();
        $this->descriptionKey = $definition->descriptionKey();
        $this->source = $definition->source();
        $this->type = $definition->type();
        $this->target = $definition->target();
        if ($usesPreviousDefaultCron) {
            $this->cronExpression = $definition->defaultCronExpression();
            $this->nextDueAt = null;
        }
        $this->defaultCronExpression = $definition->defaultCronExpression();
        $this->trusted = $definition->trusted();
        $this->metadata = $definition->metadata();
        $this->modifiedAt = $now;

        return true;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function descriptionKey(): string
    {
        return $this->descriptionKey;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function type(): SchedulerTaskType
    {
        return $this->type;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function cronExpression(): string
    {
        return $this->cronExpression;
    }

    public function defaultCronExpression(): string
    {
        return $this->defaultCronExpression;
    }

    public function status(): SchedulerTaskStatus
    {
        return $this->status;
    }

    public function trusted(): bool
    {
        return $this->trusted;
    }

    public function nextDueAt(): ?DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function lastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function lastSuccessAt(): ?DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function activate(?string $cronExpression = null): void
    {
        if (null !== $cronExpression && '' !== trim($cronExpression)) {
            $this->cronExpression = trim($cronExpression);
        }

        $this->status = SchedulerTaskStatus::Active;
        $this->failureCount = 0;
        $this->nextDueAt = null;
        $this->modifiedAt = new DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->status = SchedulerTaskStatus::Inactive;
        $this->modifiedAt = new DateTimeImmutable();
    }

    public function markAttempt(DateTimeImmutable $now): void
    {
        $this->lastAttemptAt = $now;
        $this->modifiedAt = $now;
    }

    public function markSuccess(DateTimeImmutable $now, ?DateTimeImmutable $nextDueAt): void
    {
        $this->lastSuccessAt = $now;
        $this->nextDueAt = $nextDueAt;
        $this->failureCount = 0;
        $this->status = SchedulerTaskStatus::Active;
        $this->modifiedAt = $now;
    }

    public function markFailure(DateTimeImmutable $now, int $disableAfterFailures): void
    {
        ++$this->failureCount;
        $this->nextDueAt = $now;
        if ($this->failureCount >= $disableAfterFailures) {
            $this->status = SchedulerTaskStatus::Faulty;
        }
        $this->modifiedAt = $now;
    }

    public function seedNextDue(DateTimeImmutable $nextDueAt): void
    {
        if (null === $this->nextDueAt) {
            $this->nextDueAt = $nextDueAt;
        }
    }
}
