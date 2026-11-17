<?php

declare(strict_types=1);

// crunz task file (crunz.yml: source "src/Infrastructure/Crunz/tasks", suffix "Tasks.php"). crunz loads it and expects a Crunz\Schedule back.
// With the crunz engine this replaces the Laravel engine's afterResolving(Schedule) hook in SchedulerServiceProvider.

use Application\Scheduler\RegisterSchedule;
use Application\Scheduler\SchedulerInterface;
use Infrastructure\Crunz\CrunzScheduler;
use Psr\Container\ContainerInterface;

// The project root is the nearest parent directory with vendor/autoload.php (as PHPUnit's and Pest's bin scripts find it).
for ($root = __DIR__; !is_file($root . '/vendor/autoload.php'); $root = dirname($root)) {
    if ($root === dirname($root)) {
        throw new RuntimeException('vendor/autoload.php not found above ' . __DIR__);
    }
}

require_once $root . '/vendor/autoload.php';

/** @var ContainerInterface $container */
$container = require $root . '/bootstrap/scheduler-container.php';

$scheduler = $container->get(SchedulerInterface::class);
if (!$scheduler instanceof CrunzScheduler) {
    throw new LogicException('This crunz task file is used by the crunz engine only; set SCHEDULER_ENGINE=crunz.');
}

$registerSchedule = $container->get(RegisterSchedule::class);
assert($registerSchedule instanceof RegisterSchedule);
$registerSchedule($scheduler);

return $scheduler->crunzSchedule();
