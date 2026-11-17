<?php

declare(strict_types=1);

use Domain\Lock\LockStoreInterface;
use Infrastructure\Lock\InMemoryLockStore;
use Infrastructure\Lock\PdoLockStore;
use Tests\Support\MutableClock;

// A new store (e.g. Redis) = one more line here; both the store contract and the lock suites then run against it.
dataset('lock stores', [
    'in-memory' => [static fn (MutableClock $clock): LockStoreInterface => new InMemoryLockStore($clock)],
    'pdo-sqlite' => [static fn (MutableClock $clock): LockStoreInterface => new PdoLockStore(new PDO('sqlite::memory:'), $clock)],
]);
