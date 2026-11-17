<?php

declare(strict_types=1);

use Infrastructure\Lock\PdoLockStore;
use Tests\Support\MutableClock;

it('deletes only expired rows when pruning', function (): void {
    $clock = new MutableClock();
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoLockStore($pdo, $clock);
    $store->save('short', 'A', 10);
    $store->save('long', 'B', 1000);
    $clock->advance(20);

    $store->pruneExpired();

    expect($pdo->query('SELECT lock_key FROM scheduler_locks')->fetchAll(PDO::FETCH_COLUMN))->toBe(['long']);
});

it('creates its table on first use', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoLockStore($pdo, new MutableClock());

    expect($pdo->query("SELECT name FROM sqlite_master WHERE name = 'scheduler_locks'")->fetchColumn())->toBeFalse();

    $store->save('k', 'A', 60);

    expect($store->exists('k', 'A'))->toBeTrue();
});

it('leaves the schema alone when auto setup is off', function (): void {
    (new PdoLockStore(new PDO('sqlite::memory:'), new MutableClock(), autoSetup: false))->save('k', 'A', 60);
})->throws(PDOException::class, 'no such table');
