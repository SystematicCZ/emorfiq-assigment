<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Tasks;

use Domain\Scheduler\ScheduledTask;

#[ScheduledTask('0 7 * * 1-5', timezone: 'Europe/Prague', onOneServer: true, lockTtl: 600, description: 'Daily report')]
final class SendDailyReportTask
{
    public function __invoke(): void
    {
    }
}
