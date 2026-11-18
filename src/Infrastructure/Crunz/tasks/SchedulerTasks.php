<?php

declare(strict_types=1);

// crunz task file (crunz.yml: source "src/Infrastructure/Crunz/tasks", suffix "Tasks.php"). crunz loads it and expects a Crunz\Schedule back.
// With the crunz engine this replaces the Laravel engine's afterResolving(Schedule) hook in SchedulerServiceProvider.

use Application\Scheduler\RegisterSchedule;
use Application\Scheduler\SchedulerInterface;
use Infrastructure\Crunz\CrunzScheduler;
use Psr\Container\ContainerInterface;

// crunz loads this file in its own process, outside the application, so nothing is booted here yet.
// To build the schedule (and later the tasks) it needs the application's PSR-11 container;
// how the host application hands it over is left open in this sketch.
/** @var ContainerInterface $container */
$container = throw new LogicException('Not implemented yet: obtain the application\'s PSR-11 container.');

$scheduler = $container->get(SchedulerInterface::class);
if (!$scheduler instanceof CrunzScheduler) {
    throw new LogicException('This crunz task file is used by the crunz engine only; set scheduler.engine to "crunz".');
}

$registerSchedule = $container->get(RegisterSchedule::class);
assert($registerSchedule instanceof RegisterSchedule);
$registerSchedule($scheduler);

return $scheduler->crunzSchedule();
