<?php

declare(strict_types=1);

namespace App\Scheduler;

final readonly class SchedulerLockFactory
{
    public function __construct(private string $projectDir, private string $environment)
    {
    }

    public function acquire(string $identifier = 'run'): ?SchedulerRunLock
    {
        $directory = $this->projectDir.'/var/scheduler/'.$this->environment;

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $path = $directory.'/'.preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $identifier).'.lock';
        $handle = @fopen($path, 'c');

        if (!is_resource($handle)) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new SchedulerRunLock($handle);
    }
}
