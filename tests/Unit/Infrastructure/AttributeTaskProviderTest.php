<?php

declare(strict_types=1);

use Domain\Scheduler\Exception\InvalidTaskDefinition;
use Infrastructure\Scheduler\AttributeTaskProvider;
use Infrastructure\Scheduler\DirectoryClassFinder;
use Tests\Support\Fixtures\Tasks\Nested\SyncStockTask;
use Tests\Support\Fixtures\Tasks\SendDailyReportTask;

it('finds concrete classes in a PSR-4 directory', function (): void {
    $classes = iterator_to_array(new DirectoryClassFinder(__DIR__ . '/../../Support/Fixtures/Tasks', 'Tests\\Support\\Fixtures\\Tasks'), false);

    expect($classes)->toContain(SendDailyReportTask::class, SyncStockTask::class, 'Tests\\Support\\Fixtures\\Tasks\\NotATask')
        ->not->toContain('Tests\\Support\\Fixtures\\Tasks\\AbstractBaseTask', 'Tests\\Support\\Fixtures\\Tasks\\BrokenTask');
});

it('maps attributes to definitions and ignores classes without the attribute', function (): void {
    $provider = new AttributeTaskProvider(
        new DirectoryClassFinder(__DIR__ . '/../../Support/Fixtures/Tasks', 'Tests\\Support\\Fixtures\\Tasks'),
        new DateTimeZone('UTC'),
    );

    $byId = [];
    foreach ($provider->definitions() as $definition) {
        $byId[$definition->taskClass . ' ' . $definition->cron] = $definition;
    }

    expect(array_keys($byId))->toEqualCanonicalizing([
        SendDailyReportTask::class . ' 0 7 * * 1-5',
        SyncStockTask::class . ' */15 * * * *',
        SyncStockTask::class . ' 0 3 * * *',
    ]);

    $report = $byId[SendDailyReportTask::class . ' 0 7 * * 1-5'];
    expect((string) $report->cron)->toBe('0 7 * * 1-5')
        ->and($report->taskClass)->toBe(SendDailyReportTask::class)
        ->and($report->timezone->getName())->toBe('Europe/Prague')
        ->and($report->onOneServer)->toBeTrue()
        ->and($report->withoutOverlapping)->toBeTrue()
        ->and($report->lockTtl)->toBe(600)
        ->and($report->description)->toBe('Daily report');

    $fast = $byId[SyncStockTask::class . ' */15 * * * *'];
    $full = $byId[SyncStockTask::class . ' 0 3 * * *'];
    expect($fast->withoutOverlapping)->toBeFalse()
        ->and($fast->timezone->getName())->toBe('UTC')
        ->and($full->lockTtl)->toBe(7200);
});

it('rejects unknown timezones', function (): void {
    $class = new #[Domain\Scheduler\ScheduledTask('* * * * *', timezone: 'Mars/Olympus')] class {
        public function __invoke(): void
        {
        }
    };

    iterator_to_array(new AttributeTaskProvider([$class::class], new DateTimeZone('UTC'))->definitions());
})->throws(InvalidTaskDefinition::class, 'unknown timezone');
