<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Tasks\Nested;

use Domain\Scheduler\ScheduledTask;

#[ScheduledTask('*/15 * * * *', withoutOverlapping: false)]
#[ScheduledTask('0 3 * * *', lockTtl: 7200)]
final class SyncStockTask
{
    public function __invoke(): void
    {
    }
}
