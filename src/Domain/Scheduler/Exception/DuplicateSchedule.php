<?php

declare(strict_types=1);

namespace Domain\Scheduler\Exception;

final class DuplicateSchedule extends \LogicException implements SchedulerException
{
    public function __construct(string $taskClass, string $cron)
    {
        parent::__construct(sprintf('Task %s is scheduled twice with the same cron expression "%s".', $taskClass, $cron));
    }
}
