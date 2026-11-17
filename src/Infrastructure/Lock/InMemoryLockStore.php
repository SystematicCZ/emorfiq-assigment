<?php

declare(strict_types=1);

namespace Infrastructure\Lock;

use Domain\Lock\Exception\LockConflictedException;
use Domain\Lock\LockStoreInterface;
use Domain\Lock\PrunableLockStoreInterface;
use Psr\Clock\ClockInterface;

// Process-local: for tests only. It does NOT protect against other processes or servers.
final class InMemoryLockStore implements LockStoreInterface, PrunableLockStoreInterface
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $locks = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function save(string $key, string $token, int $ttlSeconds): void
    {
        if ($this->exists($key) && !$this->exists($key, $token)) {
            throw new LockConflictedException($key);
        }

        $this->locks[$key] = ['token' => $token, 'expiresAt' => $this->now() + $ttlSeconds];
    }

    public function refresh(string $key, string $token, int $ttlSeconds): void
    {
        if (!$this->exists($key, $token)) {
            throw new LockConflictedException($key);
        }

        $this->locks[$key]['expiresAt'] = $this->now() + $ttlSeconds;
    }

    public function delete(string $key, string $token): void
    {
        if (($this->locks[$key]['token'] ?? null) === $token) {
            unset($this->locks[$key]);
        }
    }

    public function exists(string $key, ?string $token = null): bool
    {
        return isset($this->locks[$key])
            && $this->locks[$key]['expiresAt'] > $this->now()
            && ($token === null || $this->locks[$key]['token'] === $token);
    }

    public function pruneExpired(): void
    {
        $now = $this->now();
        $this->locks = array_filter($this->locks, static fn (array $lock): bool => $lock['expiresAt'] > $now);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
