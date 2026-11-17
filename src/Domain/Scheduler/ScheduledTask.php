<?php

declare(strict_types=1);

namespace Domain\Scheduler;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class ScheduledTask
{
    /**
     * @param non-empty-string      $cron
     * @param non-empty-string|null $timezone
     * @param positive-int          $lockTtl
     */
    public function __construct(
        public string $cron,
        public ?string $timezone = null,
        public bool $withoutOverlapping = true,
        public bool $onOneServer = false,
        public int $lockTtl = 3600,
        public string $description = '',
    ) {
    }
}
