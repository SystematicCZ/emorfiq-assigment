<?php

declare(strict_types=1);

use Domain\Scheduler\Exception\InvalidTaskDefinition;
use Tests\Support\Fixtures\Tasks\NotATask;
use Tests\Support\Fixtures\Tasks\SpyTask;
use Tests\Support\TaskFactory;

it('accepts an invokable class with a positive lock TTL', function (): void {
    $task = TaskFactory::definition(lockTtl: 1, taskClass: SpyTask::class);

    expect($task->taskClass)->toBe(SpyTask::class)->and($task->lockTtl)->toBe(1);
});

it('requires the task class to be invokable', function (): void {
    TaskFactory::definition(taskClass: NotATask::class);
})->throws(InvalidTaskDefinition::class, 'must be invokable');

it('requires a positive lock TTL', function (): void {
    TaskFactory::definition(lockTtl: 0);
})->throws(InvalidTaskDefinition::class, 'lockTtl');

it('is identified by its task class and cron expression', function (): void {
    expect(TaskFactory::definition(cron: '0  3 * * *', taskClass: SpyTask::class)->id)->toBe(SpyTask::class . ' 0 3 * * *');
});
