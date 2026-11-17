<?php

declare(strict_types=1);

namespace Application\Scheduler;

use Domain\Scheduler\CronExpression;

interface CronValidatorInterface
{
    public function isValid(CronExpression $cron): bool;
}
