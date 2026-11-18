<?php

declare(strict_types=1);

namespace Domain\Lock;

use Domain\Lock\Exception\LockConflictedException;

// The backend must decide and write in one step ("write only if free, expired or already mine"), never read and then write:
// exists() followed by save() would let two processes both see "free" and both save.
interface LockStoreInterface
{
    /**
     * @throws LockConflictedException
     */
    public function save(string $key, string $token, int $ttlSeconds): void;

    /**
     * @throws LockConflictedException
     */
    public function refresh(string $key, string $token, int $ttlSeconds): void;

    public function delete(string $key, string $token): void;

    public function exists(string $key, ?string $token = null): bool;
}
