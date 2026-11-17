<?php

declare(strict_types=1);

namespace Domain\Lock;

use Psr\Clock\ClockInterface;
use Random\RandomException;

final readonly class LockFactory implements LockFactoryInterface
{
    public function __construct(
        private LockStoreInterface $store,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RandomException
     */
    public function createLock(string $key, int $ttlSeconds = self::DEFAULT_TTL): LockInterface
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Lock key must not be empty.');
        }

        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException(sprintf('Lock TTL must be >= 1 second, %d given.', $ttlSeconds));
        }

        return new Lock($this->store, $this->clock, $key, bin2hex(random_bytes(16)), $ttlSeconds);
    }
}
