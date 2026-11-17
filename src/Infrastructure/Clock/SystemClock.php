<?php

declare(strict_types=1);

namespace Infrastructure\Clock;

use Psr\Clock\ClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function __construct(private ?\DateTimeZone $timezone = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone ?? new \DateTimeZone('UTC'));
    }
}
