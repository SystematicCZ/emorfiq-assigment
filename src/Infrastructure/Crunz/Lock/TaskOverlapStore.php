<?php

declare(strict_types=1);

namespace Infrastructure\Crunz\Lock;

use Domain\Lock\LockStoreInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

final readonly class TaskOverlapStore implements PersistingStoreInterface
{
    /** @param positive-int $ttlSeconds */
    public function __construct(
        private LockStoreInterface $store,
        private string $key,
        private int $ttlSeconds,
    ) {
    }

    public function save(Key $key): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function delete(Key $key): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function exists(Key $key): bool
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
