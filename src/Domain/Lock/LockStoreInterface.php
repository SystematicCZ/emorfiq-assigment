<?php

declare(strict_types=1);

namespace Domain\Lock;

use Domain\Lock\Exception\LockConflictedException;

// Each write must be ONE atomic operation in the backend ("write only if free, expired or already mine").
// exists() + save() as two steps would let two processes both see "free" and both save.
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
