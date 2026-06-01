<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Validation\Uid;
use App\Scheduler\SchedulerTaskRunStatus;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduler_task_run')]
#[ORM\Index(name: 'idx_scheduler_task_run_task_started', columns: ['task_identifier', 'started_at'])]
#[ORM\Index(name: 'idx_scheduler_task_run_status', columns: ['status'])]
class SchedulerTaskRun
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\ManyToOne(targetEntity: SchedulerTask::class)]
    #[ORM\JoinColumn(name: 'task_identifier', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
    private SchedulerTask $task;

    #[ORM\Column(enumType: SchedulerTaskRunStatus::class)]
    private SchedulerTaskRunStatus $status = SchedulerTaskRunStatus::Running;

    #[ORM\Column]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $context = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $uid, SchedulerTask $task, DateTimeImmutable $startedAt, array $context = [])
    {
        $this->uid = Uid::assert($uid, 'Scheduler task run UID');
        $this->task = $task;
        $this->startedAt = $startedAt;
        $this->context = $context;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function task(): SchedulerTask
    {
        return $this->task;
    }

    public function status(): SchedulerTaskRunStatus
    {
        return $this->status;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function finish(SchedulerTaskRunStatus $status, DateTimeImmutable $finishedAt, array $context = []): void
    {
        $this->status = $status;
        $this->finishedAt = $finishedAt;
        $this->durationMs = max(0, (int) round(((float) $finishedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
        $this->context = [
            ...$this->context,
            ...$context,
        ];
    }
}
