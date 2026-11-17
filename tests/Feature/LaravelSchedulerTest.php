<?php

declare(strict_types=1);

use App\Tasks\ExampleTask;
use Application\Scheduler\PruneExpiredLocksTask;
use Application\Scheduler\SchedulerInterface;
use Application\Scheduler\TaskProviderInterface;
use Domain\Lock\LockFactory;
use Domain\Lock\LockFactoryInterface;
use Domain\Lock\LockStoreInterface;
use Domain\Scheduler\TaskDefinition;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as LaravelEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Support\Facades\Event;
use Infrastructure\Laravel\Lock\TaskEventMutex;
use Infrastructure\Lock\InMemoryLockStore;
use Tests\Support\ArrayTaskProvider;
use Tests\Support\Fixtures\Tasks\SpyTask;
use Tests\Support\MutableClock;
use Tests\Support\TaskFactory;

function scheduleOnly(TaskDefinition ...$tasks): void
{
    app()->instance(TaskProviderInterface::class, new ArrayTaskProvider($tasks));
}

function spyTask(): SpyTask
{
    $spy = new SpyTask();
    app()->instance(SpyTask::class, $spy);

    return $spy;
}

function eventFor(string $taskClass): CallbackEvent
{
    return collect(app(Schedule::class)->events())->first(static fn (LaravelEvent $e) => str_starts_with((string) $e->description, $taskClass));
}

it('maps attribute-defined tasks onto Laravel callback events and their native features', function (): void {
    $prune = eventFor(PruneExpiredLocksTask::class);

    expect($prune)->toBeInstanceOf(CallbackEvent::class)
        ->and($prune->expression)->toBe('0 3 * * *')
        ->and($prune->timezone->getName())->toBe('Europe/Prague')
        ->and($prune->withoutOverlapping)->toBeTrue()
        ->and($prune->onOneServer)->toBeTrue()
        ->and($prune->mutex)->toBeInstanceOf(TaskEventMutex::class)
        ->and(eventFor(ExampleTask::class)->onOneServer)->toBeFalse()
        ->and(app(SchedulerInterface::class)->tasks())->toHaveCount(3);
});

it('registers one Laravel event per schedule, sharing one lock per class', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (LaravelEvent $e) => str_starts_with((string) $e->description, ExampleTask::class));

    expect($events->map(static fn (LaravelEvent $e) => $e->expression)->values()->all())->toBe(['* * * * *', '*/2 * * * *']);
});

it('lets Laravel run a due task and releases our lock afterwards', function (): void {
    scheduleOnly(TaskFactory::definition());
    $spy = spyTask();

    $this->artisan('schedule:run')->assertSuccessful();

    expect($spy->calls)->toBe(1)
        ->and(app(LockStoreInterface::class)->exists('task:' . SpyTask::class))->toBeFalse();
});

it('holds our lock while the task runs', function (): void {
    scheduleOnly(TaskFactory::definition());
    $spy = spyTask();
    $spy->onRun = static fn () => expect(app(LockStoreInterface::class)->exists('task:' . SpyTask::class))->toBeTrue();

    $this->artisan('schedule:run')->assertSuccessful();

    expect($spy->calls)->toBe(1);
});

it('skips a task whose lock is held by another process or server', function (): void {
    scheduleOnly(TaskFactory::definition());
    $spy = spyTask();
    app(LockFactoryInterface::class)->createLock('task:' . SpyTask::class, 60)->acquire();

    $this->artisan('schedule:run')->assertSuccessful();

    expect($spy->calls)->toBe(0);
});

it('reports a failing task through Laravel and still releases the lock', function (): void {
    scheduleOnly(TaskFactory::definition());
    $spy = spyTask();
    $spy->onRun = static fn () => throw new RuntimeException('database is down');
    Event::fake([ScheduledTaskFailed::class]);

    $this->artisan('schedule:run');

    Event::assertDispatched(ScheduledTaskFailed::class, static fn (ScheduledTaskFailed $e) => $e->exception->getMessage() === 'database is down');
    expect(app(LockStoreInterface::class)->exists('task:' . SpyTask::class))->toBeFalse();
});

it('runs an onOneServer task only on the first server that claims the minute', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(10));
    scheduleOnly(TaskFactory::definition(onOneServer: true));
    $spy = spyTask();
    app(SchedulingMutex::class)->create(eventFor(SpyTask::class), now());   // another server was first

    $this->artisan('schedule:run')
        ->expectsOutputToContain('already ran on another server')
        ->assertSuccessful();

    expect($spy->calls)->toBe(0);
});

it('does not release a lock that expired mid-run and was taken over by a newer run', function (): void {
    $clock = new MutableClock();
    $store = new InMemoryLockStore($clock);
    $locks = new LockFactory($store, $clock);
    $mutex = new TaskEventMutex($locks, $store, 'task:X', 60);
    $event = app(Schedule::class)->call(static fn () => null)->name('x');

    expect($mutex->create($event))->toBeTrue();
    $clock->advance(61);                                         // the run outlived its lockTtl…
    expect($locks->createLock('task:X', 60)->acquire())->toBeTrue(); // …and a newer run took over

    $mutex->forget($event);                                      // the old run finishes late

    expect($store->exists('task:X'))->toBeTrue();
});

it('keeps one scheduler instance when it is resolved before Laravel’s Schedule', function (): void {
    $scheduler = app(SchedulerInterface::class);   // what app:scheduler:run does first
    app(Schedule::class);

    expect($scheduler->tasks())->toHaveCount(3)
        ->and(app(SchedulerInterface::class))->toBe($scheduler);
});
