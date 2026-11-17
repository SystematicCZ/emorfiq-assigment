<?php

declare(strict_types=1);

namespace Infrastructure\Laravel\Lock;

use Domain\Lock\LockFactoryInterface;
use Domain\Lock\LockStoreInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;

// Laravel's onOneServer(): the first server to claim an event's minute runs it. Slot locks are never released,
// so no owner token is needed and binding this globally is safe for every event.
final readonly class LockSchedulingMutex implements SchedulingMutex
{
    private const int SLOT_TTL = 3600;

    public function __construct(
        private LockFactoryInterface $locks,
        private LockStoreInterface $store,
    ) {
    }

    public function create(Event $event, \DateTimeInterface $time): bool
    {
        return $this->locks->createLock($this->key($event, $time), self::SLOT_TTL)->acquire();
    }

    public function exists(Event $event, \DateTimeInterface $time): bool
    {
        return $this->store->exists($this->key($event, $time));
    }

    // Laravel's own key uses only "Hi", which repeats every day; the full UTC minute is unambiguous.
    /** @return non-empty-string */
    private function key(Event $event, \DateTimeInterface $time): string
    {
        $minute = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('YmdHi');

        return 'slot:' . $event->mutexName() . ':' . $minute;
    }
}
