<?php

declare(strict_types=1);

namespace Infrastructure\Scheduler;

use Application\Scheduler\CronValidatorInterface;
use Cron\CronExpression as DragonmantankCron;
use Domain\Scheduler\CronExpression;

// The same library Laravel and crunz use internally, so "valid for us" == "valid for the engine".
final class CronValidator implements CronValidatorInterface
{
    public function isValid(CronExpression $cron): bool
    {
        return DragonmantankCron::isValidExpression($cron->expression);
    }
}
