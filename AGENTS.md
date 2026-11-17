# Scheduler + Lock abstraction (Laravel today, crunz tomorrow)

Tasks are defined **our way**: an invokable class with one PHP attribute and a crontab expression. They are handed to the **scheduling engine**, which does the work: Laravel 12 today, [crunz](https://github.com/crunzphp/crunz) possibly tomorrow. Laravel's unreliable cache mutex is replaced by **our own lock** (`LockFactory` → `Lock`), plugged into Laravel's own mutex extension points.

```php
#[ScheduledTask('0 7 * * 1-5', timezone: 'Europe/Prague', onOneServer: true, lockTtl: 600)]
final readonly class SendDailyReportTask
{
    public function __construct(private ReportMailer $mailer) {}

    public function __invoke(): void
    {
        $this->mailer->sendDailyReport();
    }
}
```

---

## Quick start

```bash
composer setup                            # composer install, .env, app key and the SQLite file in var/

php artisan schedule:list                 # Laravel lists our tasks ("Has Mutex" = our lock is held)
php artisan schedule:test                 # Laravel runs one task on demand
php artisan schedule:work                 # local dev: run the scheduler every minute in the foreground
```

Production crontab, which stays the same if the engine changes:

```cron
* * * * * cd /path/to/app && php artisan app:scheduler:run >> /dev/null 2>&1
```

Quality checks:

```bash
composer check                       # everything below
composer lint                        # Pint, PSR-12 preset + project rules (pint.json); `composer fix` applies them
composer stan                        # PHPStan level 10 with Larastan (phpstan.neon)
./vendor/bin/pest                    # tests (unit, integration, feature)
```

### Try it by hand

```bash
# Two schedules of one class: ExampleTask prints the time every minute, and twice in every even minute
php artisan schedule:work
#   10:45:02 … ExampleTask ran                     ← odd minute: only "* * * * *"
#   10:46:01 … ExampleTask ran                     ← even minute: "* * * * *" …
#   10:46:01 … ExampleTask ran                     ←               … and "*/2 * * * *"

# Overlap + crash: take the task's lock from a process that then exits without releasing it (= a crashed run)
php artisan tinker --execute="app(Domain\Lock\LockFactoryInterface::class)->createLock('task:App\\Tasks\\ExampleTask', 120)->acquire();"
php artisan schedule:list      # → ExampleTask: "Has Mutex"
php artisan schedule:run       # → ExampleTask is skipped while the lock is held
# … 120 s later the abandoned lock expires (our TTL, not Laravel's 24 h) and the task runs again
```

---

## 1. What the two engines offer, and what we abstract

| Capability | Laravel 12 Scheduler | crunz | In our definition |
|---|---|---|---|
| Trigger | system cron → `schedule:run` every minute | system cron → `crunz schedule:run` every minute | one entrypoint, `app:scheduler:run` → `SchedulerInterface::runDue()` |
| Frequency | `cron()` + fluent helpers (`daily()`, …) | `cron()` + fluent helpers | **cron string only**: both engines accept it, and helpers are sugar |
| Timezone per task | `timezone()` | `timezone()` | **yes** |
| Target | artisan command, closure, job, shell | shell command, closure | **invokable class**, resolved and called by the engine |
| Prevent overlap | `withoutOverlapping()` → `EventMutex` (pluggable) | `preventOverlapping($store)` → Symfony Lock store (pluggable) | **yes**, through each engine's own feature, with **our lock** plugged in |
| One server only | `onOneServer()` → `SchedulingMutex` (pluggable) | – | **yes**: Laravel natively; a crunz adapter would add it with our lock |
| Hooks / events | `ScheduledTaskStarting/Finished/Failed/Skipped` | before/after/onError | the engine's own |
| Sub-minute, maintenance mode, environments, run-as-user | partly | partly | no: not portable, not needed |
| Catch-up of missed runs | **no** | **no** | **no** (see §6, decision 7) |

What our definition carries is what both engines support: **cron + timezone + an invokable target + overlap and one-server protection**. Anything only one engine supports was left out on purpose. Otherwise the abstraction would leak the engine it came from.

## 2. Why our own lock: what is unreliable in Laravel's mutex

By default Laravel's scheduler locks through `CacheEventMutex` (`withoutOverlapping`) and `CacheSchedulingMutex` (`onOneServer`), see `vendor/laravel/framework/src/Illuminate/Console/Scheduling/`:

| Problem | Consequence |
|---|---|
| The lock is a cache key with a TTL of **24 h by default** (`withoutOverlapping($expiresAt = 1440)`) | If the process is killed (OOM, `kill -9`, a deploy, a server restart), the lock stays. The task **silently doesn't run for up to a day**. |
| One fixed TTL with no way to extend it | If a run takes longer than the TTL, the lock expires and a **second copy starts**. |
| `forget()` always uses `forceRelease()`, and plain cache stores have no owner | A late or stray release **removes someone else's lock**. |
| It is stored in the **cache** | Eviction under memory pressure or `cache:clear` drops locks. The `file` and `array` drivers are not shared between servers. |
| The lock name is `sha1(cron expression + command)` | Changing the cron expression changes the lock name, so the old and new runs don't exclude each other. |
| The `onOneServer` slot key is `mutexName + "Hi"` | It repeats every day, so correctness depends on the 1 h TTL. |

Laravel itself is fine; only the mutex is the problem, and Laravel lets you replace it:
- `$event->preventOverlapsUsing(EventMutex $mutex)` sets the overlap mutex **per event**;
- the `SchedulingMutex` binding in the container is used by `onOneServer()` for the whole schedule.

How our lock fixes each problem:
- **Owner token.** Each lock gets a random token, and only its owner can refresh or release it. A late release by a former owner does nothing.
- **TTL per task** (`lockTtl`, in seconds), sized to the task. A crashed run blocks the task only for that long, not 24 h.
- **Atomic acquire, including takeover of an expired lock**, done with single SQL statements. This is checked by an integration test where 12 OS processes race for one lock.
- **Durable, pluggable storage** (a DB table, not the cache). Redis would be one more store class.
- **Stable keys** `task:{TaskClass}`, unaffected by cron changes. One-server slot keys use the full UTC minute (`YmdHi`).

**Scope:** the overlap mutex is replaced only on the events we register (`preventOverlapsUsing`). Events registered directly in Laravel's `Schedule`, for example by vendor packages, keep Laravel's cache mutex. `onOneServer` is replaced for all events, which is safe because slot locks are never released.

## 3. Architecture: onion / hexagonal

There are three layers, one top-level folder each, and dependencies point **inward only**:

```
        ┌────────────────────────────── Infrastructure ──────────────────────────────┐
        │  Laravel/   SchedulerServiceProvider (composition root), LaravelScheduler,   │
        │             Lock/TaskEventMutex, Lock/LockSchedulingMutex, app:scheduler:run │
        │  Lock/      PdoLockStore, InMemoryLockStore        (no Laravel – reusable)   │
        │  Scheduler/ AttributeTaskProvider, DirectoryClassFinder,                     │
        │             CronValidator                          (no Laravel – reusable)   │
        │  Clock/     SystemClock (PSR-20)                                             │
        │     ┌─────────────────────────── Application ───────────────────────────┐    │
        │     │  Scheduler/  TaskRegistry, RegisterSchedule                        │    │
        │     │              ports: SchedulerInterface (engine),                   │    │
        │     │              TaskProviderInterface, CronValidatorInterface         │    │
        │     │     ┌───────────────────────── Domain ──────────────────────┐      │    │
        │     │     │  Scheduler/  #[ScheduledTask], TaskDefinition,         │      │    │
        │     │     │              CronExpression                            │      │    │
        │     │     │  Lock/       LockFactoryInterface, LockInterface,      │      │    │
        │     │     │              LockFactory, Lock, LockStoreInterface     │      │    │
        │     │     └────────────────────────────────────────────────────────┘      │    │
        │     └─────────────────────────────────────────────────────────────────────┘    │
        └─────────────────────────────────────────────────────────────────────────────────┘
   app/Tasks/  – the application's concrete tasks; they depend on the Domain only
```

```
src/
  Domain/                   what things ARE: vocabulary, invariants, rules. Only PHP + PSR.
    Lock/                   locking (owner token, TTL, takeover of expired locks) – standalone, knows no scheduler
    Scheduler/              the attribute, the task definition, the cron value object
  Application/              what HAPPENS. Depends on Domain only.
    Scheduler/              TaskRegistry (collect, check cron, reject duplicates), RegisterSchedule (hand tasks to the engine),
                            and the ports SchedulerInterface, TaskProviderInterface, CronValidatorInterface
  Infrastructure/           which TECHNOLOGY: implements ports, wires everything
    Lock/                   lock stores (SQLite via PDO, in-memory)
    Scheduler/              attribute reader, class finder, cron library adapter
    Clock/                  SystemClock
    Laravel/                everything that imports Illuminate\*
app/Tasks/                  ExampleTask (two schedules)
```

**Assumptions about the host.** The host here is a Laravel app, but only one part of the module needs that:
- `Domain`, `Application`, `Infrastructure\Lock` and `Infrastructure\Scheduler` need only PHP and PSR interfaces.
- **The Laravel engine needs the Laravel runtime** (container, console, events). That is inherent in using Laravel's scheduler at all.
- **The crunz engine needs only crunz and a PSR-11 container.** The host provides that container in one file, `bootstrap/scheduler-container.php`, used by the crunz task file and by `Infrastructure/Crunz/bin/scheduler`. Here it returns Laravel's `Application`, which implements PSR-11. A non-Laravel host returns its own container. Nothing in `Infrastructure\Crunz` (including its task file and `bin/scheduler`) imports `Illuminate`.
- `Infrastructure/Laravel` is the **Laravel bridge**: everything needed to use the module inside a Laravel host. That covers the composition root (`SchedulerServiceProvider`), the `app:scheduler:run` command and the config wiring, plus the Laravel *engine* (`LaravelScheduler`, the mutexes). A Symfony host would get a sibling `Infrastructure/Symfony` bridge (a bundle wiring the ports, a console command for `runDue()`), the way libraries ship `Bridge/Laravel` next to `Bridge/Symfony`. Nothing existing is rewritten, and `app/` only holds the host's own tasks.

**The Laravel skeleton is reduced to a console app.** Only what the scheduler needs is left:

```
app/Tasks/            the application's tasks
bootstrap/            app.php (exception handling, points Laravel's generated files at var/), providers.php (SchedulerServiceProvider),
                      scheduler-container.php (the PSR-11 container the crunz path uses)
config/               database.php (SQLite busy_timeout + WAL) and scheduler.php; since Laravel 11 missing config files fall back to framework defaults
var/                  not in git; everything generated at runtime: Laravel's storage (app/, framework/, logs/), its bootstrap cache
                      (bootstrap-cache/) and the SQLite file; there are no migrations, because the lock table creates itself on first use
src/                  Domain, Application, Infrastructure (crunz's task file and bin/scheduler live in Infrastructure/Crunz)
tests/                unit, integration, feature
```

Removed from the default skeleton: `public/`, `resources/`, `routes/`, Vite and `package.json`, `app/Http`, `app/Models`, `app/Providers`, the users/cache/jobs migrations, factories and seeders, every config file except `database.php` (and our `scheduler.php`), and `laravel/pail`, `laravel/sail` and `fakerphp/faker`. `.env` uses `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` and `SESSION_DRIVER=file`, because the database tables for those are gone.

The project is small, so each layer is split by module (`Lock`, `Scheduler`) rather than into full DDD bounded contexts. If it grew, the next step would be to flip it, `Scheduler/{Domain,Application,Infrastructure}`, so each context owns its own layers.

**Composition root.** `SchedulerServiceProvider` is the only place that maps interfaces to implementations. Laravel autowires concrete classes (`LockFactory`, `RegisterSchedule`, `TaskRegistry`, the tasks) by their constructor types. No class gets the container injected: it appears only in the provider. `LaravelScheduler` receives `Schedule` as a lazy `\Closure(): Schedule`, Laravel's equivalent of Symfony's service closure (`#[AutowireServiceClosure]`). That breaks a resolution cycle: building `Schedule` fires the hook that needs the scheduler. So the provider holds only:
- five interface → class mappings in its declarative `$singletons` array;
- two factories that need config values (the lock store's PDO and table, the task paths and default timezone);
- one framework hook: when Laravel builds its `Schedule`, run `RegisterSchedule`.

Symfony would need the same three things in `services.yaml` or a bundle extension. It is more automatic only for aliasing an interface that has a single implementation. Laravel 12's `#[Bind]` attribute could do that here too, but it would have to sit on our Domain interfaces and tie them to Laravel, which the dependency rules forbid.

**Where ports live.** The rule is: *in the innermost layer that needs them*.
- `LockStoreInterface` is used by the domain's `Lock` itself, so it lives in **Domain**. It plays the role a repository interface plays in DDD.
- `SchedulerInterface`, `TaskProviderInterface` and `CronValidatorInterface` are needed only by the application layer, so they live in **Application**.

**Dependency rules:**
- `Domain` uses none of `Application`, `Infrastructure`, `App`, `Illuminate`, `Cron`, `Symfony`, `PDO` or `Carbon`.
- `Application` uses none of `Infrastructure`, `App` or the frameworks and libraries.
- `Domain\Lock` does not use `Domain\Scheduler` or `Application`, because locking is a standalone module.
- `Infrastructure\Lock`, `Infrastructure\Scheduler` and `Infrastructure\Clock` don't use `Illuminate`, so they can be reused with crunz.
- `App\Tasks` are invokable, carry `#[ScheduledTask]`, and use neither `Application`, `Infrastructure` nor `Illuminate`.
- Every class in `Domain\Lock\Exception` implements `LockException`, and every class in `Domain\Scheduler\Exception` implements `SchedulerException`.

### Runtime flow (Laravel)

```
crontab ──► artisan app:scheduler:run ──► SchedulerInterface::runDue() ──► artisan schedule:run
                                                │
     Laravel builds its Schedule ──► (afterResolving) RegisterSchedule ──► LaravelScheduler::schedule()
                                                │   TaskRegistry ◄─ AttributeTaskProvider ◄─ app/Tasks/*
                                                ▼
     per task:  $schedule->call(TaskClass::class)->cron()->timezone()->name()
                    ->withoutOverlapping()->preventOverlapsUsing(new TaskEventMutex(our lock, task:TaskClass, lockTtl))
                    ->onOneServer()                           // SchedulingMutex bound to LockSchedulingMutex
                                                │
                                                ▼
     Laravel, for each due task (in-process, one after another):
         onOneServer?        → LockSchedulingMutex::create()  slot:<mutexName>:<YmdHi>  (never released)
         withoutOverlapping? → TaskEventMutex::create()        task:<TaskClass>  (our lockTtl)  → held? skip
         container resolves TaskClass, calls __invoke()        → ScheduledTaskStarting / Finished / Failed
         TaskEventMutex::forget()                              → releases with OUR token, same process
```

## 4. The interfaces in detail

### Locks: `Domain\Lock`

```php
interface LockFactoryInterface
{
    /** Creates a handle with its own owner token. NOT acquired yet. */
    public function createLock(string $key, int $ttlSeconds = 300): LockInterface;   // non-empty key, ttl >= 1
}

interface LockInterface
{
    public function acquire(): bool;                         // non-blocking; re-acquire extends; usable again after release
    public function isAcquired(): bool;                      // WE hold it (checked in the store)
    public function refresh(?int $ttlSeconds = null): void;  // extend; LockLostException if taken over, LogicException if not acquired
    public function release(): void;                         // idempotent; deletes only our own lock
    public function expiresAt(): ?\DateTimeImmutable;        // null when not acquired
}

interface LockStoreInterface            // the backend port – stores and queries, never decides
{
    public function save(string $key, string $token, int $ttlSeconds): void;     // free / expired / already mine → stored; else LockConflictedException
    public function refresh(string $key, string $token, int $ttlSeconds): void;  // still mine → extended; else LockConflictedException
    public function delete(string $key, string $token): void;                    // only if mine, otherwise nothing
    public function exists(string $key, ?string $token = null): bool;            // anyone's / mine when token given
}
```

Typical use outside the scheduler:

```php
$lock = $locks->createLock('import:stock', ttlSeconds: 600);
if (!$lock->acquire()) {
    return;                       // someone else is importing
}
try {
    foreach ($batches as $batch) { import($batch); $lock->refresh(); }
} finally {
    $lock->release();
}
```

`LockFactory` (logic) is separate from `LockStoreInterface` (storage). The factory validates input and gives every handle a random 128-bit owner token. `Lock` holds that token and **owns the lock's state**: it decides "acquired / not acquired / lost" from the outcome of the store's writes.

**The store only stores and queries.** Its writes are `void`. A write it can't make because someone else holds the key is reported as `LockConflictedException`, which `Lock` turns into `acquire() === false` or `LockLostException`. The one rule the store must enforce itself is **atomicity**: "write only if free, expired or already mine" has to be a single operation in the backend, like a unique index. `Lock` can't do `if (!exists()) save()`, because two processes could both see "free" and both save.

`PdoLockStore` implements only SQLite: one driver arm, plus a placeholder comment where a MySQL/Postgres/MariaDB arm would go. Note for a future MySQL arm: MySQL's `rowCount()` counts *changed* rows, not matched ones. A same-second refresh would then return 0, so 0 must be confirmed with `exists(key, token)` before reporting a conflict. The store API takes a TTL rather than a timestamp, so Redis can use native `PX` expiry while SQL computes `expires_at` itself. Symfony Lock uses the same split and the same `createLock()` → `acquire()` shape.

**Why a factory plus a handle, rather than `acquire($key): ?Lock`.** The handle exists before anyone holds the lock, so one object answers "do I hold it?" (`isAcquired()`) and "try again later" (`acquire()` on the same handle), with no `null` checks. The cost is that a caller can forget to check `acquire()`'s result; `refresh()` on a lock that was never acquired throws, so that mistake shows up quickly.

**The lock only knows about itself.** As in Symfony, `LockInterface` has `isAcquired()` ("do *I* hold it") but no "does *anyone* hold it". That question goes to the store, `LockStoreInterface::exists($key)`. Laravel uses it for "Has Mutex" in `schedule:list`. It is never used as "check, then acquire"; to *get* the lock you always call `acquire()`.

### Scheduler: `Domain\Scheduler` + `Application\Scheduler`

```php
// Domain
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class ScheduledTask {
    public function __construct(
        public string  $cron,                      // 5-field crontab (no @daily-style macros)
        public ?string $timezone = null,           // IANA; default config('scheduler.timezone') ?? app.timezone
        public bool    $withoutOverlapping = true, // safe default: skip while a previous run is still running
        public bool    $onOneServer = false,       // multi-server: first server to claim the minute wins
        public int     $lockTtl = 3600,            // seconds; must exceed the longest run; max block time after a crash
        public string  $description = '',
    ) {}
}
// a task = any invokable class with #[ScheduledTask]: public function __invoke(): void; throw = failure
final class TaskDefinition { CronExpression $cron; class-string $taskClass;                          // value object:
                                      \DateTimeZone $timezone; bool $withoutOverlapping; bool $onOneServer;   // invokable class and
                                      int $lockTtl; string $description;                                    // lockTtl >= 1 checked here
                                      string $id { get; } }                    // computed: "<taskClass> <cron>"; stored properties are readonly
final readonly class CronExpression { string $expression; static fromString(string): self; }   // shape check only

// Application – ports
interface SchedulerInterface {                       // the engine: Laravel now, crunz later
    public function schedule(TaskDefinition $task): void;
    /** @return list<TaskDefinition> */ public function tasks(): array;
    public function runDue(): void;                  // the every-minute entrypoint
}
interface TaskProviderInterface { /** @return iterable<TaskDefinition> */ public function definitions(): iterable; }
interface CronValidatorInterface { public function isValid(CronExpression $cron): bool; }

// Application
final class          TaskRegistry     { all(): list<TaskDefinition> }      // collect from providers, check cron validity, reject duplicates
final readonly class RegisterSchedule { __invoke(SchedulerInterface $engine): void }
```

## 5. Configuration (`config/scheduler.php`)

| Key | Default | Meaning |
|---|---|---|
| `timezone` | `null` → `app.timezone` | Timezone for tasks that don't set one |
| `engine` | `laravel` | Which scheduling engine runs the tasks: `laravel` or `crunz`. The provider binds the matching `SchedulerInterface` and its framework hooks. `crunz` without the package fails with "composer require crunzphp/crunz", and an unknown name fails clearly. |
| `locks.store` | `database` | Which lock store to use, one of the keys under `locks.stores` (same shape as Laravel's `config/cache.php`). Only `database` is implemented; a future `redis` store would be added here. An unknown name fails with a clear error. |
| `locks.stores.database.table` | `scheduler_locks` | |

**Task discovery follows Laravel's convention.** Tasks live in `app/Tasks`, the same way commands live in `app/Console/Commands` and listeners in `app/Listeners`, and the directory is not configurable. Like Laravel's own discovery, `DirectoryClassFinder` reflects each class there and skips files that can't be loaded (a missing parent class, a parse error) instead of breaking the scan. Symfony instead scans all of `src/` at container compile time (`registerAttributeForAutoconfiguration` → a `scheduler.task` tag → a compiler pass building `RecurringMessage`s) and caches the result. We rebuild the schedule on every `schedule:run`, so scanning only a dedicated folder keeps that cheap. A Symfony adapter would use Symfony's mechanism, and the attribute would stay the same.

There is one task source, bound as `TaskProviderInterface` (the attribute reader). Another source (a config file, a database) would be one more `TaskProviderInterface`, combined through a small chain provider. `TaskRegistry` and the rest stay as they are.

## 6. Decisions and the alternatives considered

1. **Cron string as the only way to define frequency.** Both engines evaluate cron with the same library (`dragonmantank/cron-expression`), so it is the format they share exactly. Fluent helpers would mean copying one engine's API into ours. *Alternative:* our own fluent builder. Rejected because it adds API surface and needs tests without adding any capability.

2. **The attribute is the whole contract; a task is just an invokable class.** The attribute keeps *when* next to the code. This is Symfony's `#[AsCronTask]` style, which also only requires an invokable class. It is easy to find, there is no central list to maintain, and it can be repeated for several schedules. There is no marker interface: `TaskDefinition` checks that the class has `__invoke`. Tasks get no arguments, as in Laravel and Symfony, and get their dependencies from the DI container through the constructor. `TaskDefinition` is a separate value object, because the attribute is only one *source*: its timezone is optional and raw, and engine adapters must not depend on reflection. It guards its own invariants (invokable class, `lockTtl >= 1`), so an invalid definition cannot exist, whatever source built it. `TaskRegistry` adds the two checks a single definition can't make: cron validity (needs the port) and duplicates. *Alternatives:* (a) a `RecurringTaskInterface` with a `getSchedule()` method mixes configuration and behaviour and needs an instance just to read the cron; (b) a central config file is easy to review but drifts away from the code. Because sources are behind `TaskProviderInterface`, (b) can be added without touching anything else.

3. **There is no task name.** As in Symfony, where `#[AsCronTask]`/`#[AsPeriodicTask]` are repeatable and have no name, a schedule is identified by *what* runs plus *when*: task class + cron expression. That identity is the computed property `TaskDefinition::$id`, used by the registry and by both engine adapters. The same class with the same cron twice is rejected (`DuplicateSchedule`). Lock keys are **per class** (`task:App\Tasks\X`). All schedules of a class do the same work, so they must not overlap each other, and changing a cron expression doesn't change the lock.

4. **Our layer defines the tasks; the engine does the work.** `LaravelScheduler` maps each `TaskDefinition` onto Laravel's own features: `call()`, `cron()`, `timezone()`, `withoutOverlapping()`, `onOneServer()`. Laravel then evaluates due tasks, runs them, handles failures (`ScheduledTaskFailed`, the exception handler), and provides `schedule:list`, `schedule:test`, `schedule:work` and its events. Our code is the task definition, the lock, and two small mutex adapters. *Alternative considered (and built first):* a thin engine and a thick core, where Laravel only spawned `app:scheduler:run-task` and our own `RunTask` use case did locking, events and error handling. It was fully engine-independent, but it re-implemented what the engine already does, and Laravel did almost nothing. The cost of the chosen design is that guarantees now live in each engine adapter: a crunz adapter has to plug our lock into crunz and add one-server itself (see §7).

5. **In-process runs, so the owner token holds.** Our tasks are Laravel *callback* events, which Laravel runs in the `schedule:run` process. It calls `EventMutex::create()` and `forget()` in that same process, so `TaskEventMutex` releases with the lock it acquired: owner-safe. *Alternative:* `runInBackground()` command events. They run in parallel, but Laravel releases them from a separate `schedule:finish` process that only knows the event name. That would need a release-by-key-only, which is exactly what the token exists to prevent. Cost of the choice: tasks due in the same minute run **one after another**, so a long task delays the rest of that minute. Long work should be dispatched as a queued job, which is Laravel's own advice.

6. **The lock logic is separate from its store.** Logic (tokens, validation, the `Lock` handle) is written and tested once. A store only has to do 4 operations (`save`, `refresh`, `delete`, `exists`), and every store runs **the same contract tests** (`tests/Unit/Lock/LockStoreContractTest.php` and `LockContractTest.php`). SQLite was picked because it needs no extra service for this task. Production would more likely use MySQL/Postgres (one more driver arm in `PdoLockStore`) or Redis (a new store).

7. **Missed runs are not caught up.** Neither Laravel nor crunz does it; both are stateless and only check "is it due now?". Catching up needs stored "last run" state, and there are choices to make (run all missed, run once, skip). Running every missed occurrence is usually harmful: after a 1 h outage, 60 runs of a per-minute task.

8. **Three layers as top-level folders; a thin domain.** A scheduler is a technical subject with few business rules, so `Domain\Scheduler` is just the attribute, the definition and the cron value object, and `Application` collects and hands tasks to the engine. `Domain\Lock` holds the real rules: owner token, TTL, takeover only after expiry, release only by the owner. Ports live in the innermost layer that needs them: the lock store in Domain; the engine, task sources and cron validation in Application.

9. **The cron library is behind a port** (`CronValidatorInterface::isValid()`), so Domain and Application have no third-party dependency. `CronExpression` itself only checks the *shape* of the expression (exactly 5 fields; `@daily`-style macros are deliberately not supported, since `0 0 * * *` says the same).

10. **A dedicated DB connection for locks.** A lock row written inside an open transaction is invisible to other processes until commit, so a second process could "acquire" the same lock. It also disappears on rollback. The scheduler itself takes and releases locks outside the tasks' transactions. But the lock module is general-purpose, and a task or any other code may use `LockFactory` inside its own `DB::transaction()`, or a task may leave a transaction open. So the lock store **always** gets its own clone of the default connection (`LockConnection`), by design and not configurable. Lock writes therefore always commit immediately, whatever the application is doing on its connection. The one exception is SQLite `:memory:` (tests): there a second connection would be a different, empty database, so the default connection is reused.

11. **Exceptions: an SPL parent for the kind, a module marker interface for the origin.** `LockConflictedException`/`LockLostException` extend `\RuntimeException` (a runtime condition), `InvalidTaskDefinition` extends `\InvalidArgumentException`, and `DuplicateSchedule` extends `\LogicException` (a configuration bug). Each also implements its module's marker, `LockException` or `SchedulerException`, so a caller can `catch (LockException)` for anything from the lock module. The markers are interfaces, not a shared base class, because PHP allows only one parent and the SPL kind would be lost; Symfony components do the same with `Exception\ExceptionInterface`. There is one marker per module, not one for the whole domain, because `Domain\Lock` must stay independent of the scheduler. PHP's own `\DomainException` is not used: in SPL it means "value outside the valid range", not "thrown by the domain layer". Exceptions are created with plain constructors that take the domain values (`new LockConflictedException($key)`) and build their own message.

## 7. Switching to crunz

The adapter is prepared as **shells**: signatures only, with method bodies that throw `LogicException('Not implemented yet.')`. The exceptions are `CrunzScheduler::schedule()`, which shows how a task is registered with crunz, the crunz task file, and the invoke command with `bin/scheduler`, which need nothing from crunz. Checked against crunz 3.9 with `scheduler.engine` set to `crunz`: the task file builds a real `Crunz\Schedule` through the PSR-11 container file, with one event per schedule running `php src/Infrastructure/Crunz/bin/scheduler app:scheduler:invoke '<TaskClass>'`. crunz isn't installed; it is listed under `suggest` in `composer.json`, together with the `symfony/lock` it brings. The shells were checked against crunz 3.9 and `symfony/lock`: they load and implement the interfaces.

| File | Role |
|---|---|
| `src/Infrastructure/Crunz/CrunzScheduler.php` | `SchedulerInterface` over `Crunz\Schedule`. `schedule()` maps a `TaskDefinition` to `$schedule->run('php …/Crunz/bin/scheduler app:scheduler:invoke <TaskClass>')->cron()->timezone()`. `withoutOverlapping` → `->preventOverlapping(new TaskOverlapStore(…))`. `onOneServer` → `->when(fn () => $this->claimSlot(…))`, because crunz has no one-server feature. `runDue()` runs `vendor/bin/crunz schedule:run`. |
| `src/Infrastructure/Crunz/Lock/TaskOverlapStore.php` | Symfony `PersistingStoreInterface` over our `LockStoreInterface`, one instance per event, like `TaskEventMutex`. crunz acquires in a skip filter and releases in `after()`/`onError()` in the same process, so the owner token (kept in the Symfony `Key`) makes release owner-safe. Our per-class key and `lockTtl` replace crunz's own key and fixed TTL. |
| `src/Infrastructure/Crunz/Console/InvokeTaskCommand.php` + `src/Infrastructure/Crunz/bin/scheduler` | `app:scheduler:invoke <TaskClass>`, a plain Symfony Console command over a PSR-11 container: `$container->get($taskClass)()`. crunz runs shell commands, so each event calls `php src/Infrastructure/Crunz/bin/scheduler app:scheduler:invoke …`. An exception exits non-zero, which crunz treats as failure: error callbacks run and the lock is released. A closure (`$schedule->run(fn …)`) would not avoid this: crunz runs closures in its own `closure:run` process without the host container, and only serializable values can be captured. |
| `bootstrap/scheduler-container.php` | The host's PSR-11 container: the only host-specific piece on the crunz path. |
| `src/Infrastructure/Crunz/tasks/SchedulerTasks.php` | The crunz task file (`crunz.yml`: `source: src/Infrastructure/Crunz/tasks`, `suffix: Tasks.php`). It gets the container from `bootstrap/scheduler-container.php`, runs `RegisterSchedule` with `CrunzScheduler`, and returns its `Crunz\Schedule`. |

In this Laravel host, the provider already has both engines wired, selected by `scheduler.engine`:
- `laravel` (default): binds `LaravelScheduler` and `LockSchedulingMutex`, and hooks `RegisterSchedule` into Laravel's `Schedule` resolution.
- `crunz`: binds `CrunzScheduler` and `Crunz\Schedule`. There is no `Schedule` hook, because the crunz task file calls `RegisterSchedule` itself.

Switching, once the crunz classes are finished:
1. `composer require crunzphp/crunz`
2. add `crunz.yml` (`vendor/bin/crunz publish:config`; `source: src/Infrastructure/Crunz/tasks`, `suffix: Tasks.php`)
3. set `'engine' => 'crunz'` in `config/scheduler.php`

Apart from that one config value, no PHP file changes, and the crontab line stays `php artisan app:scheduler:run`: `runDue()` now starts crunz.

Nothing in `Infrastructure\Crunz` (including its task file and `bin/scheduler`) uses `Illuminate` or `Infrastructure\Laravel`.

What is reused without changes:
- `Domain/*` and `Application/*`.
- `app/Tasks/*`.
- `Infrastructure/Lock/*` and `Infrastructure/Scheduler/*`.
- The crontab line: `* * * * * … app:scheduler:run`.

## 8. Adding a Redis lock store

```php
final readonly class RedisLockStore implements LockStoreInterface
{
    // Redis expires keys natively, so "expired" simply means "absent".
    public function save(string $k, string $t, int $ttl): void
    {
        $ok = $this->eval('local v = redis.call("get", KEYS[1]) if v == false or v == ARGV[1] then return redis.call("set", KEYS[1], ARGV[1], "PX", ARGV[2]) end return false', $k, $t, $ttl * 1000);
        if (!$ok) { throw new LockConflictedException($k); }
    }
    public function refresh(string $k, string $t, int $ttl): void
    {
        $ok = $this->eval('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("pexpire", KEYS[1], ARGV[2]) end return 0', $k, $t, $ttl * 1000);
        if (!$ok) { throw new LockConflictedException($k); }
    }
    public function delete(string $k, string $t): void { $this->eval('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) end return 0', $k, $t); }
    public function exists(string $k, ?string $t = null): bool { $v = $this->redis->get($k); return $v !== false && ($t === null || $v === $t); }
}
```

Then add it to the `lock stores` dataset (`tests/Datasets/LockStores.php`), so both the store contract and the lock behaviour suites run against it, add a `redis` entry under `locks.stores` in the config, and add a `'redis' => new RedisLockStore(…)` arm to the `match` in `SchedulerServiceProvider`, where the placeholder comment is. Then set `locks.store` to `redis` (any of these values could come from an env variable). Redis expires keys natively, so it needs no cleanup of its own. Nothing else changes.

## 9. Known limitations and trade-offs

- **Tasks due in the same minute run sequentially** (decision 5). Keep scheduled tasks short, or dispatch a queued job for long work.
- **How Laravel reports a skipped overlap.** `withoutOverlapping()` adds a skip filter that asks `EventMutex::exists()`, our store, before running. `schedule:run` therefore skips a locked task and dispatches `ScheduledTaskSkipped`, printing nothing for it. The atomic `create()` inside `run()` is what actually decides a race between two processes. `schedule:test` bypasses filters, so there a skipped run shows as `DONE`.
- **`lockTtl` must be longer than the task's longest run.** If a run takes longer, its lock expires and the next run can start alongside it. The late release of the first run is harmless, because the owner token stops it from deleting the new owner's lock (covered by a test). `lockTtl` is also the longest a crashed run blocks the task, so it is a trade-off: long enough for a slow run, short enough for quick recovery.
- **No force unlock.** Neither the lock nor the store can delete a lock it doesn't own. A stuck lock always clears by itself after its `lockTtl`. In an emergency, an operator deletes the row in `scheduler_locks` by hand.
- **Clock skew.** Lock expiry uses the app servers' clocks. Use NTP; a skew of a few seconds doesn't matter for TTLs measured in minutes. *Alternative:* take the time from the DB, at the cost of SQL that differs per driver.
- **One-server slot rows** are never reused, so expired rows pile up. `PdoLockStore` deletes expired rows itself on a random share of saves (1 %), the way Symfony's PDO lock store does, so there is no cleanup task to schedule. Expired rows that are still there do no harm: `save()` takes them over and `exists()` ignores them.
- **SQLite** is fine for one server (WAL and `busy_timeout` are set in `config/database.php`). Several servers need a shared DB server (add its driver arm to `PdoLockStore`) or a Redis store.

## 10. Tests

The suite covers the parts worth highlighting: the lock, the lock stores, the scheduler implementation, and how tasks are discovered and validated.

| Suite | What it proves |
|---|---|
| `tests/Unit/Lock/LockContractTest` | `Lock` + `LockFactory` against **every** store: create without acquiring, acquire, conflict, re-acquire extends, takeover of an expired lock, owner-safe release, refresh, lost lock, invalid input |
| `tests/Unit/Lock/LockStoreContractTest` | The `LockStoreInterface` contract against **every** store implementation: void writes, `LockConflictedException` on a refused save or refresh, owner re-save, takeover of an expired lock, a same-second refresh is not a conflict, owner-only delete |
| `tests/Unit/Infrastructure/PdoLockStoreTest` | Garbage collection while saving deletes only expired rows (checked in the SQLite table); with it off, an expired row stays and still doesn't block; the table is created on first use |
| `tests/Integration/ConcurrentAcquireTest` | 12 real OS processes race for one lock on a shared SQLite file: **exactly one wins** |
| `tests/Feature/LaravelSchedulerTest` | `LaravelScheduler` and its mutexes against Laravel's real `schedule:run`: tasks mapped onto native features, one event per schedule with one lock per class, run and release, lock held during the run, skip while locked, failure reported with the lock released, onOneServer skip, no release of a lock taken over by a newer run, one scheduler instance |
| `tests/Unit/Infrastructure/AttributeTaskProviderTest` | `#[ScheduledTask]` → `TaskDefinition`, repeatable attributes, default timezone, invalid timezone; the class finder skips abstract and unloadable classes |
| `tests/Unit/Scheduler/CronExpressionTest` | The cron shape rule: exactly 5 fields, whitespace normalised, `@daily`-style macros and stray characters rejected |
| `tests/Unit/Scheduler/TaskDefinitionTest` | The definition's own invariants: the class must be invokable, `lockTtl` must be at least 1; its `id` is class + cron |
| `tests/Unit/Scheduler/TaskRegistryTest` | Sorting, several schedules per class, duplicate class + cron, invalid cron |
