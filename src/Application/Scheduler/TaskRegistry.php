<?php

declare(strict_types=1);

namespace Application\Scheduler;

use Domain\Scheduler\Exception\DuplicateSchedule;
use Domain\Scheduler\Exception\InvalidTaskDefinition;
use Domain\Scheduler\TaskDefinition;

// A task is identified by its class + cron expression (like Symfony's message + trigger); there is no name.
final class TaskRegistry
{
    /** @var array<string, TaskDefinition>|null keyed by "<task class> <cron>" */
    private ?array $tasks = null;

    public function __construct(
        private readonly TaskProviderInterface $provider,
        private readonly CronValidatorInterface $cronValidator,
    ) {
    }

    /** @return list<TaskDefinition> */
    public function all(): array
    {
        return array_values($this->load());
    }

    /** @return array<string, TaskDefinition> */
    private function load(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }

        $tasks = [];
        foreach ($this->provider->definitions() as $definition) {
            $this->validate($definition);

            if (isset($tasks[$definition->id])) {
                throw new DuplicateSchedule($definition->taskClass, $definition->cron->expression);
            }

            $tasks[$definition->id] = $definition;
        }

        ksort($tasks, SORT_STRING);

        return $this->tasks = $tasks;
    }

    private function validate(TaskDefinition $task): void
    {
        if (!$this->cronValidator->isValid($task->cron)) {
            throw new InvalidTaskDefinition(sprintf('%s: cron expression "%s" is not valid', $task->taskClass, $task->cron));
        }
    }
}
