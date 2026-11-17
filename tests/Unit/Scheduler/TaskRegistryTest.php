<?php

declare(strict_types=1);

use Application\Scheduler\TaskRegistry;
use Domain\Scheduler\Exception\DuplicateSchedule;
use Domain\Scheduler\Exception\InvalidTaskDefinition;
use Domain\Scheduler\TaskDefinition;
use Infrastructure\Scheduler\CronValidator;
use Tests\Support\ArrayTaskProvider;
use Tests\Support\Fixtures\Tasks\Nested\SyncStockTask;
use Tests\Support\Fixtures\Tasks\SpyTask;
use Tests\Support\TaskFactory;

function registry(TaskDefinition ...$definitions): TaskRegistry
{
    return new TaskRegistry(new ArrayTaskProvider($definitions), new CronValidator());
}

it('collects definitions sorted by class', function (): void {
    $registry = registry(
        TaskFactory::definition(taskClass: SpyTask::class),
        TaskFactory::definition(taskClass: SyncStockTask::class),
    );

    expect(array_map(static fn ($t) => $t->taskClass, $registry->all()))->toBe([SyncStockTask::class, SpyTask::class]);
});

it('allows one class on several schedules', function (): void {
    expect(registry(TaskFactory::definition(cron: '*/15 * * * *'), TaskFactory::definition(cron: '0 3 * * *'))->all())->toHaveCount(2);
});

it('rejects the same class scheduled twice with the same cron', function (): void {
    registry(TaskFactory::definition(), TaskFactory::definition())->all();
})->throws(DuplicateSchedule::class);

it('rejects semantically invalid cron expressions', function (): void {
    registry(TaskFactory::definition(cron: '61 * * * *'))->all();
})->throws(InvalidTaskDefinition::class, 'is not valid');
