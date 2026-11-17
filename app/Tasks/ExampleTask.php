<?php

declare(strict_types=1);

namespace App\Tasks;

use Domain\Scheduler\ScheduledTask;

#[ScheduledTask('* * * * *', description: 'Prints the current time (every minute)')]
#[ScheduledTask('*/2 * * * *', description: 'Prints the current time (every two minutes)')]
final readonly class ExampleTask
{
    public function __invoke(): void
    {
        echo date('Y-m-d H:i:s') . ' ExampleTask ran' . PHP_EOL;
    }
}
