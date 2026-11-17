<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Scheduler\CronExpression;
use Domain\Scheduler\TaskDefinition;
use Tests\Support\Fixtures\Tasks\SpyTask;

final class TaskFactory
{
    public static function definition(
        string $cron = '* * * * *',
        bool $withoutOverlapping = true,
        bool $onOneServer = false,
        int $lockTtl = 60,
        string $timezone = 'UTC',
        string $taskClass = SpyTask::class,
        string $description = '',
    ): TaskDefinition {
        return new TaskDefinition(
            cron: CronExpression::fromString($cron),
            taskClass: $taskClass,
            timezone: new \DateTimeZone($timezone),
            withoutOverlapping: $withoutOverlapping,
            onOneServer: $onOneServer,
            lockTtl: $lockTtl,
            description: $description,
        );
    }
}
