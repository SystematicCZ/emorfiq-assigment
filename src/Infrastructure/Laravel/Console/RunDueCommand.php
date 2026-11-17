<?php

declare(strict_types=1);

namespace Infrastructure\Laravel\Console;

use Application\Scheduler\SchedulerInterface;
use Illuminate\Console\Command;

final class RunDueCommand extends Command
{
    protected $signature = 'app:scheduler:run';

    protected $description = 'Run all scheduled tasks that are due now (call every minute from cron)';

    public function handle(SchedulerInterface $scheduler): int
    {
        $scheduler->runDue();

        return self::SUCCESS;
    }
}
