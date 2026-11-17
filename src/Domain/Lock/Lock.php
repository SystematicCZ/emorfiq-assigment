<?php

declare(strict_types=1);

namespace Domain\Lock;

use Domain\Lock\Exception\LockConflictedException;
use Domain\Lock\Exception\LockLostException;
use Psr\Clock\ClockInterface;

final class Lock implements LockInterface
{
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(
        private readonly LockStoreInterface $store,
        private readonly ClockInterface $clock,
        private readonly string $key,
        private readonly string $token,
        private readonly int $ttlSeconds,
    ) {
    }

    public function acquire(): bool
    {
        try {
            $this->store->save($this->key, $this->token, $this->ttlSeconds);
        } catch (LockConflictedException) {
            $this->expiresAt = null;

            return false;
        }

        $this->expiresAt = $this->expiryFromNow($this->ttlSeconds);

        return true;
    }

    public function isAcquired(): bool
    {
        return $this->expiresAt !== null && $this->store->exists($this->key, $this->token);
    }

    public function refresh(?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? $this->ttlSeconds;
        if ($ttl < 1) {
            throw new \InvalidArgumentException(sprintf('Lock TTL must be >= 1 second, %d given.', $ttl));
        }

        if ($this->expiresAt === null) {
            throw new \LogicException(sprintf('Lock "%s" cannot be refreshed: it is not acquired.', $this->key));
        }

        try {
            $this->store->refresh($this->key, $this->token, $ttl);
        } catch (LockConflictedException $e) {
            $this->expiresAt = null;

            throw new LockLostException($this->key, $e);
        }

        $this->expiresAt = $this->expiryFromNow($ttl);
    }

    public function release(): void
    {
        if ($this->expiresAt === null) {
            return;
        }

        $this->store->delete($this->key, $this->token);
        $this->expiresAt = null;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    private function expiryFromNow(int $ttlSeconds): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds));
    }
}
