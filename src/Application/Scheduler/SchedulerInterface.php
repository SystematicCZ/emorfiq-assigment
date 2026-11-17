<?php

declare(strict_types=1);

namespace Application\Scheduler;

use Domain\Scheduler\TaskDefinition;

interface SchedulerInterface
{
    public function schedule(TaskDefinition $task): void;

    /** @return list<TaskDefinition> */
    public function tasks(): array;

    // The single entrypoint the system crontab calls every minute; it stays the same when the engine changes.
    public function runDue(): void;
}
