<?php

declare(strict_types=1);

use Domain\Lock\Exception\LockLostException;
use Domain\Lock\LockFactory;
use Infrastructure\Lock\InMemoryLockStore;
use Tests\Support\MutableClock;

function lockFactory(Closure $storeFactory, MutableClock $clock): LockFactory
{
    return new LockFactory($storeFactory($clock), $clock);
}

it('creates a lock that is not acquired yet', function (Closure $store): void {
    $lock = lockFactory($store, new MutableClock())->createLock('job', 60);

    expect($lock->isAcquired())->toBeFalse()
        ->and($lock->expiresAt())->toBeNull();
})->with('lock stores');

it('acquires a free lock', function (Closure $store): void {
    $clock = new MutableClock();
    $lock = lockFactory($store, $clock)->createLock('job', 60);

    expect($lock->acquire())->toBeTrue()
        ->and($lock->isAcquired())->toBeTrue()
        ->and($lock->expiresAt())->toEqual($clock->now()->modify('+60 seconds'));
})->with('lock stores');

it('does not acquire a lock held by someone else', function (Closure $store): void {
    $locks = lockFactory($store, new MutableClock());
    $first = $locks->createLock('job', 60);
    $second = $locks->createLock('job', 60);

    expect($first->acquire())->toBeTrue()
        ->and($second->acquire())->toBeFalse()
        ->and($second->isAcquired())->toBeFalse()
        ->and($first->isAcquired())->toBeTrue();
})->with('lock stores');

it('keeps independent keys independent', function (Closure $store): void {
    $locks = lockFactory($store, new MutableClock());

    expect($locks->createLock('a', 60)->acquire())->toBeTrue()
        ->and($locks->createLock('b', 60)->acquire())->toBeTrue();
})->with('lock stores');

it('extends the lock when acquiring a lock it already holds', function (Closure $store): void {
    $clock = new MutableClock();
    $lock = lockFactory($store, $clock)->createLock('job', 60);

    $lock->acquire();
    $clock->advance(50);

    expect($lock->acquire())->toBeTrue()
        ->and($lock->expiresAt())->toEqual($clock->now()->modify('+60 seconds'));
})->with('lock stores');

it('takes over an expired (abandoned) lock', function (Closure $store): void {
    $clock = new MutableClock();
    $locks = lockFactory($store, $clock);
    $crashed = $locks->createLock('job', 60);
    $crashed->acquire();                      // owner dies, never releases

    $clock->advance(60);
    $next = $locks->createLock('job', 60);

    expect($next->acquire())->toBeTrue()
        ->and($crashed->isAcquired())->toBeFalse();
})->with('lock stores');

it('releases so the lock can be acquired again', function (Closure $store): void {
    $locks = lockFactory($store, new MutableClock());
    $lock = $locks->createLock('job', 60);
    $lock->acquire();

    $lock->release();

    expect($lock->isAcquired())->toBeFalse()
        ->and($locks->createLock('job', 60)->acquire())->toBeTrue();
})->with('lock stores');

it('can re-acquire the same handle after release', function (Closure $store): void {
    $lock = lockFactory($store, new MutableClock())->createLock('job', 60);

    $lock->acquire();
    $lock->release();

    expect($lock->acquire())->toBeTrue();
})->with('lock stores');

it('never releases a lock that was taken over by someone else', function (Closure $store): void {
    $clock = new MutableClock();
    $locks = lockFactory($store, $clock);
    $slow = $locks->createLock('job', 60);
    $slow->acquire();

    $clock->advance(61);                     // slow owner exceeded its TTL
    $newOwner = $locks->createLock('job', 60);
    $newOwner->acquire();
    $slow->release();                        // late release of the old owner must be a no-op

    expect($newOwner->isAcquired())->toBeTrue()
        ->and($locks->createLock('job', 60)->acquire())->toBeFalse();
})->with('lock stores');

it('makes release idempotent and a no-op on never-acquired locks', function (Closure $store): void {
    $locks = lockFactory($store, new MutableClock());
    $holder = $locks->createLock('job', 60);
    $holder->acquire();

    $locks->createLock('job', 60)->release(); // a competitor that never acquired must not delete it
    $holder->release();
    $holder->release();

    expect($locks->createLock('job', 60)->acquire())->toBeTrue();
})->with('lock stores');

it('extends the lock on refresh (heartbeat)', function (Closure $store): void {
    $clock = new MutableClock();
    $locks = lockFactory($store, $clock);
    $lock = $locks->createLock('job', 60);
    $lock->acquire();

    $clock->advance(50);
    $lock->refresh();                        // now valid until +50+60
    $clock->advance(50);                     // 100s after acquire, > original TTL

    expect($lock->isAcquired())->toBeTrue()
        ->and($locks->createLock('job', 60)->acquire())->toBeFalse()
        ->and($lock->expiresAt())->toEqual($clock->now()->modify('+10 seconds'));
})->with('lock stores');

it('refreshes with a custom TTL', function (Closure $store): void {
    $clock = new MutableClock();
    $lock = lockFactory($store, $clock)->createLock('job', 10);
    $lock->acquire();

    $lock->refresh(300);
    $clock->advance(200);

    expect($lock->isAcquired())->toBeTrue();
})->with('lock stores');

it('throws on refresh of an expired lock', function (Closure $store): void {
    $clock = new MutableClock();
    $lock = lockFactory($store, $clock)->createLock('job', 60);
    $lock->acquire();
    $clock->advance(60);

    $lock->refresh();
})->with('lock stores')->throws(LockLostException::class);

it('throws on refresh of a lock taken over by someone else', function (Closure $store): void {
    $clock = new MutableClock();
    $locks = lockFactory($store, $clock);
    $lock = $locks->createLock('job', 60);
    $lock->acquire();
    $clock->advance(61);
    $locks->createLock('job', 60)->acquire();

    try {
        $lock->refresh();
    } finally {
        expect($lock->isAcquired())->toBeFalse();
    }
})->with('lock stores')->throws(LockLostException::class);

it('refuses to refresh a lock that is not acquired', function (Closure $store, bool $releasedFirst): void {
    $lock = lockFactory($store, new MutableClock())->createLock('job', 60);
    if ($releasedFirst) {
        $lock->acquire();
        $lock->release();
    }

    $lock->refresh();
})->with('lock stores')->with(['never acquired' => false, 'released' => true])->throws(LogicException::class, 'not acquired');

it('rejects invalid arguments', function (string $key, int $ttl): void {
    (new LockFactory(new InMemoryLockStore(new MutableClock()), new MutableClock()))->createLock($key, $ttl);
})->with([
    'empty key' => ['', 60],
    'zero ttl' => ['job', 0],
    'negative ttl' => ['job', -5],
])->throws(InvalidArgumentException::class);
