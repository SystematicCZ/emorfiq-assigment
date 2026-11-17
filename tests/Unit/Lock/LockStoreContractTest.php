<?php

declare(strict_types=1);

use Domain\Lock\Exception\LockConflictedException;
use Tests\Support\MutableClock;

it('saves a free key', function (Closure $store): void {
    $s = $store(new MutableClock());

    $s->save('k', 'A', 60);

    expect($s->exists('k'))->toBeTrue()
        ->and($s->exists('k', 'A'))->toBeTrue()
        ->and($s->exists('k', 'B'))->toBeFalse();
})->with('lock stores');

it('refuses to save a key held by another owner', function (Closure $store): void {
    $s = $store(new MutableClock());
    $s->save('k', 'A', 60);

    $s->save('k', 'B', 60);
})->with('lock stores')->throws(LockConflictedException::class);

it('lets the owner save again, which extends the lock', function (Closure $store): void {
    $clock = new MutableClock();
    $s = $store($clock);
    $s->save('k', 'A', 60);
    $clock->advance(50);

    $s->save('k', 'A', 60);
    $clock->advance(50);

    expect($s->exists('k', 'A'))->toBeTrue();
})->with('lock stores');

it('lets anyone save over an expired lock', function (Closure $store): void {
    $clock = new MutableClock();
    $s = $store($clock);
    $s->save('k', 'A', 60);
    $clock->advance(60);

    $s->save('k', 'B', 60);

    expect($s->exists('k', 'B'))->toBeTrue()
        ->and($s->exists('k', 'A'))->toBeFalse();
})->with('lock stores');

it('refreshes a lock held by the token, also twice within the same second', function (Closure $store): void {
    $clock = new MutableClock();
    $s = $store($clock);
    $s->save('k', 'A', 60);

    $s->refresh('k', 'A', 60);
    $s->refresh('k', 'A', 60);    // identical expires_at – must not be mistaken for a conflict
    $clock->advance(59);

    expect($s->exists('k', 'A'))->toBeTrue();
})->with('lock stores');

it('refuses to refresh for a non-owner, an expired or a missing lock', function (Closure $store, string $case): void {
    $clock = new MutableClock();
    $s = $store($clock);
    $s->save('k', 'A', 60);

    match ($case) {
        'non-owner' => $s->refresh('k', 'B', 60),
        'expired' => [$clock->advance(60), $s->refresh('k', 'A', 60)],
        'missing' => $s->refresh('other', 'A', 60),
    };
})->with('lock stores')->with(['non-owner', 'expired', 'missing'])->throws(LockConflictedException::class);

it('deletes only for the owner and silently ignores others', function (Closure $store): void {
    $s = $store(new MutableClock());
    $s->save('k', 'A', 60);

    $s->delete('k', 'B');
    expect($s->exists('k', 'A'))->toBeTrue();

    $s->delete('k', 'A');
    expect($s->exists('k'))->toBeFalse();
})->with('lock stores');
