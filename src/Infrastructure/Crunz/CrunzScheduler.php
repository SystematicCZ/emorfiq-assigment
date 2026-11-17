<?php

declare(strict_types=1);

namespace Infrastructure\Crunz;

use Application\Scheduler\SchedulerInterface;
use Crunz\Event;
use Crunz\Schedule;
use Domain\Lock\LockFactoryInterface;
use Domain\Lock\LockStoreInterface;
use Domain\Scheduler\TaskDefinition;
use Infrastructure\Crunz\Lock\TaskOverlapStore;

final class CrunzScheduler implements SchedulerInterface
{
    /** @var array<string, TaskDefinition> */
    private array $tasks = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly LockFactoryInterface $locks,
        private readonly LockStoreInterface $lockStore,
    ) {
    }

    public function schedule(TaskDefinition $task): void
    {
        if (isset($this->tasks[$task->id])) {
            return;
        }

        // crunz runs shell commands, not PHP callables: each event calls our bin/scheduler next to this class.
        $event = $this->schedule
            ->run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bin/scheduler') . ' app:scheduler:invoke ' . escapeshellarg($task->taskClass))
            ->cron($task->cron->expression)
            ->timezone($task->timezone)
            ->description($task->description !== '' ? "{$task->taskClass}: {$task->description}" : $task->taskClass);

        if ($task->withoutOverlapping) {
            // crunz accepts a Symfony Lock store here, so our lock is plugged in as one.
            $event->preventOverlapping(new TaskOverlapStore($this->lockStore, 'task:' . $task->taskClass, $task->lockTtl));
        }

        if ($task->onOneServer) {
            $event->when(fn (): bool => $this->claimSlot($task, $event));
        }

        $this->tasks[$task->id] = $task;
    }

    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    public function runDue(): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function crunzSchedule(): Schedule
    {
        return $this->schedule;
    }

    // crunz has no onOneServer(): the first server to claim the minute's slot lock runs the event.
    private function claimSlot(TaskDefinition $task, Event $event): bool
    {
        throw new \LogicException('Not implemented yet.');
    }
}
