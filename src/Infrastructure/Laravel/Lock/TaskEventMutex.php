<?php

declare(strict_types=1);

namespace Infrastructure\Laravel\Lock;

use Domain\Lock\LockFactoryInterface;
use Domain\Lock\LockInterface;
use Domain\Lock\LockStoreInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

// One instance per scheduled event (set via preventOverlapsUsing). Our tasks run in-process, so Laravel calls
// create() and forget() in the same `schedule:run` process: the lock held here keeps release owner-safe.
// The key is per task class and the TTL is our lockTtl in seconds; Laravel's mutexName and expiresAt are ignored.
final class TaskEventMutex implements EventMutex
{
    private ?LockInterface $lock = null;

    /**
     * @param non-empty-string $key
     * @param positive-int     $ttlSeconds
     */
    public function __construct(
        private readonly LockFactoryInterface $locks,
        private readonly LockStoreInterface $store,
        private readonly string $key,
        private readonly int $ttlSeconds,
    ) {
    }

    public function create(Event $event): bool
    {
        $this->lock = $this->locks->createLock($this->key, $this->ttlSeconds);

        return $this->lock->acquire();
    }

    public function exists(Event $event): bool
    {
        return $this->store->exists($this->key);
    }

    public function forget(Event $event): void
    {
        $this->lock?->release();
        $this->lock = null;
    }
}
