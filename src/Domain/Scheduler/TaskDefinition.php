<?php

declare(strict_types=1);

namespace Domain\Scheduler;

use Domain\Scheduler\Exception\InvalidTaskDefinition;

final class TaskDefinition
{
    public string $id {
        get => $this->taskClass . ' ' . $this->cron->expression;
    }

    /**
     * @param class-string $taskClass
     * @param positive-int $lockTtl
     */
    public function __construct(
        public readonly CronExpression $cron,
        public readonly string $taskClass,
        public readonly \DateTimeZone $timezone,
        public readonly bool $withoutOverlapping,
        public readonly bool $onOneServer,
        public readonly int $lockTtl,
        public readonly string $description,
    ) {
        if (!method_exists($taskClass, '__invoke')) {
            throw new InvalidTaskDefinition(sprintf('class %s must be invokable', $taskClass));
        }

        if ($lockTtl < 1) {
            throw new InvalidTaskDefinition(sprintf('%s: lockTtl must be >= 1 second, %d given', $taskClass, $lockTtl));
        }
    }
}
