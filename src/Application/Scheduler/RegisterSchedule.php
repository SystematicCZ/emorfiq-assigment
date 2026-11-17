<?php

declare(strict_types=1);

namespace Application\Scheduler;

final readonly class RegisterSchedule
{
    public function __construct(private TaskRegistry $registry)
    {
    }

    // The engine is passed in, not injected: an adapter may only exist once the framework has built its schedule.
    public function __invoke(SchedulerInterface $scheduler): void
    {
        foreach ($this->registry->all() as $task) {
            $scheduler->schedule($task);
        }
    }
}
