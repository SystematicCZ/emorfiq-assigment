<?php

declare(strict_types=1);

namespace Infrastructure\Laravel;

use Application\Scheduler\CronValidatorInterface;
use Application\Scheduler\RegisterSchedule;
use Application\Scheduler\SchedulerInterface;
use Application\Scheduler\TaskProviderInterface;
use Domain\Lock\LockFactory;
use Domain\Lock\LockFactoryInterface;
use Domain\Lock\LockStoreInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ArtisanKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Infrastructure\Clock\SystemClock;
use Infrastructure\Crunz\CrunzScheduler;
use Infrastructure\Laravel\Console\RunDueCommand;
use Infrastructure\Laravel\Lock\LockSchedulingMutex;
use Infrastructure\Lock\PdoLockStore;
use Infrastructure\Scheduler\AttributeTaskProvider;
use Infrastructure\Scheduler\CronValidator;
use Infrastructure\Scheduler\DirectoryClassFinder;
use Psr\Clock\ClockInterface;

final class SchedulerServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        ClockInterface::class => SystemClock::class,
        LockFactoryInterface::class => LockFactory::class,
        CronValidatorInterface::class => CronValidator::class,
    ];

    public function register(): void
    {
        $this->app->singleton(LockStoreInterface::class, static fn (Application $app): LockStoreInterface => match ($store = self::config($app)->string('scheduler.locks.store')) {
            'database' => new PdoLockStore(
                $app->make(DatabaseManager::class)->connection(LockConnection::name(self::config($app)))->getPdo(),
                $app->make(ClockInterface::class),
                self::config($app)->string('scheduler.locks.stores.database.table'),
            ),
            // 'redis' => new RedisLockStore(...), – future stores are selected here
            default => throw new \InvalidArgumentException(sprintf('Unknown scheduler lock store "%s".', $store)),
        });

        $this->app->singleton(TaskProviderInterface::class, static fn (Application $app): AttributeTaskProvider => new AttributeTaskProvider(
            self::taskClasses($app),
            new \DateTimeZone(self::defaultTimezone(self::config($app))),
        ));

        match ($engine = self::config($this->app)->string('scheduler.engine')) {
            'laravel' => $this->registerLaravelEngine(),
            'crunz' => $this->registerCrunzEngine(),
            default => throw new \InvalidArgumentException(sprintf('Unknown scheduler engine "%s".', $engine)),
        };
    }

    public function boot(): void
    {
        $this->commands([RunDueCommand::class]);

        if (self::config($this->app)->string('scheduler.engine') === 'crunz') {
            // The crunz task file (src/Infrastructure/Crunz/tasks/SchedulerTasks.php) calls RegisterSchedule itself.
            return;
        }

        // Laravel builds its Schedule for every schedule:run / schedule:list / schedule:work → hand it our tasks.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule, Application $app): void {
            $app->make(RegisterSchedule::class)($app->make(SchedulerInterface::class));
        });
    }

    /** @return list<class-string> */
    private static function taskClasses(Application $app): array
    {
        // Laravel-style convention directory, like app/Console/Commands or app/Listeners.
        return iterator_to_array(new DirectoryClassFinder($app->path('Tasks'), $app->getNamespace() . 'Tasks'), false);
    }

    private static function config(Application $app): Repository
    {
        return $app->make(Repository::class);
    }

    private static function defaultTimezone(Repository $config): string
    {
        $timezone = $config->get('scheduler.timezone');

        return is_string($timezone) ? $timezone : $config->string('app.timezone');
    }

    private function registerLaravelEngine(): void
    {
        $this->app->singleton(SchedulerInterface::class, static fn (Application $app): LaravelScheduler => new LaravelScheduler(
            static fn (): Schedule => $app->make(Schedule::class),
            $app->make(ArtisanKernel::class),
            $app->make(LockFactoryInterface::class),
            $app->make(LockStoreInterface::class),
        ));
        $this->app->singleton(SchedulingMutex::class, LockSchedulingMutex::class);
    }

    private function registerCrunzEngine(): void
    {
        if (!class_exists(\Crunz\Schedule::class)) {
            throw new \LogicException('The crunz scheduler engine needs the crunz package: composer require crunzphp/crunz');
        }

        $this->app->singleton(\Crunz\Schedule::class);
        $this->app->singleton(SchedulerInterface::class, CrunzScheduler::class);
    }
}
