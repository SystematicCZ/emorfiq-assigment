<?php

declare(strict_types=1);

namespace Domain\Scheduler\Exception;

final class InvalidTaskDefinition extends \InvalidArgumentException implements SchedulerException
{
    public function __construct(string $reason)
    {
        parent::__construct('Invalid scheduled task definition: ' . $reason . '.');
    }
}
