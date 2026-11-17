<?php

declare(strict_types=1);

namespace Infrastructure\Laravel;

use Application\Scheduler\SchedulerInterface;
use Domain\Lock\LockFactoryInterface;
use Domain\Lock\LockStoreInterface;
use Domain\Scheduler\TaskDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ArtisanKernel;
use Infrastructure\Laravel\Lock\TaskEventMutex;
use Symfony\Component\Console\Output\ConsoleOutput;

final class LaravelScheduler implements SchedulerInterface
{
    /** @var array<string, TaskDefinition> */
    private array $tasks = [];

    // Schedule comes as a lazy closure (like Symfony's service closure): resolving it fires the provider's
    // afterResolving hook, which resolves this scheduler again – a direct dependency would build a second instance.
    /** @param \Closure(): Schedule $schedule */
    public function __construct(
        private readonly \Closure $schedule,
        private readonly ArtisanKernel $artisan,
        private readonly LockFactoryInterface $locks,
        private readonly LockStoreInterface $lockStore,
    ) {
    }

    public function schedule(TaskDefinition $task): void
    {
        if (isset($this->tasks[$task->id])) {
            return;
        }

        // A class-string callback: Laravel's container resolves the task and calls __invoke().
        $event = ($this->schedule)()->call($task->taskClass)
            ->cron($task->cron->expression)
            ->timezone($task->timezone)
            ->name($task->description !== '' ? "{$task->taskClass}: {$task->description}" : $task->taskClass);

        if ($task->withoutOverlapping) {
            $event->withoutOverlapping()->preventOverlapsUsing(
                new TaskEventMutex($this->locks, $this->lockStore, 'task:' . $task->taskClass, $task->lockTtl),
            );
        }

        if ($task->onOneServer) {
            $event->onOneServer();
        }

        $this->tasks[$task->id] = $task;
    }

    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    public function runDue(): void
    {
        // Artisan::call() buffers output by default; a console output keeps Laravel's "Running […] DONE" lines visible.
        $this->artisan->call('schedule:run', [], new ConsoleOutput());
    }
}
