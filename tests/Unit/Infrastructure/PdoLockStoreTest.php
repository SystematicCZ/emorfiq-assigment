<?php

declare(strict_types=1);

use Infrastructure\Lock\PdoLockStore;
use Tests\Support\MutableClock;

it('deletes expired rows, and only those, while saving', function (): void {
    $clock = new MutableClock();
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoLockStore($pdo, $clock, gcProbability: 1.0);
    $store->save('short', 'A', 10);
    $store->save('long', 'B', 1000);
    $clock->advance(20);

    $store->save('other', 'C', 60);

    expect($pdo->query('SELECT lock_key FROM scheduler_locks ORDER BY lock_key')->fetchAll(PDO::FETCH_COLUMN))->toBe(['long', 'other'])
        ->and($store->exists('long', 'B'))->toBeTrue();
});

it('leaves expired rows in place when garbage collection is off, and they still do not block', function (): void {
    $clock = new MutableClock();
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoLockStore($pdo, $clock, gcProbability: 0.0);
    $store->save('k', 'A', 10);
    $clock->advance(20);

    expect($pdo->query('SELECT COUNT(*) FROM scheduler_locks')->fetchColumn())->toEqual(1)
        ->and($store->exists('k'))->toBeFalse();

    $store->save('k', 'B', 10);

    expect($store->exists('k', 'B'))->toBeTrue();
});

it('rejects a garbage collection probability outside 0..1', function (): void {
    new PdoLockStore(new PDO('sqlite::memory:'), new MutableClock(), gcProbability: 1.5);
})->throws(InvalidArgumentException::class, 'between 0 and 1');

it('creates its table on first use', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoLockStore($pdo, new MutableClock());

    expect($pdo->query("SELECT name FROM sqlite_master WHERE name = 'scheduler_locks'")->fetchColumn())->toBeFalse();

    $store->save('k', 'A', 60);

    expect($store->exists('k', 'A'))->toBeTrue();
});
