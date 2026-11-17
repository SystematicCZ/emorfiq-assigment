<?php

declare(strict_types=1);

namespace Application\Scheduler;

use Domain\Lock\PrunableLockStoreInterface;
use Domain\Scheduler\ScheduledTask;

// One-server slot locks are never released or reused (one row per task per minute), so their rows are pruned nightly.
#[ScheduledTask('0 3 * * *', timezone: 'Europe/Prague', onOneServer: true, lockTtl: 600, description: 'Deletes expired lock rows')]
final readonly class PruneExpiredLocksTask
{
    public function __construct(private PrunableLockStoreInterface $locks)
    {
    }

    public function __invoke(): void
    {
        $this->locks->pruneExpired();
    }
}
