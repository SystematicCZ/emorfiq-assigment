<?php

declare(strict_types=1);

namespace Domain\Lock;

interface LockFactoryInterface
{
    public const int DEFAULT_TTL = 300;

    /**
     * @param non-empty-string $key
     * @param positive-int     $ttlSeconds
     */
    public function createLock(string $key, int $ttlSeconds = self::DEFAULT_TTL): LockInterface;
}
