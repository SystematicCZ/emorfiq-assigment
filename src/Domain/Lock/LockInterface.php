<?php

declare(strict_types=1);

namespace Domain\Lock;

use Domain\Lock\Exception\LockLostException;

interface LockInterface
{
    public function acquire(): bool;

    public function isAcquired(): bool;

    /**
     * @param positive-int|null $ttlSeconds
     *
     * @throws LockLostException
     * @throws \LogicException
     */
    public function refresh(?int $ttlSeconds = null): void;

    public function release(): void;

    public function expiresAt(): ?\DateTimeImmutable;
}
