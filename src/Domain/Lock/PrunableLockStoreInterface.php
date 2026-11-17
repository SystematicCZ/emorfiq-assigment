<?php

declare(strict_types=1);

namespace Domain\Lock;

interface PrunableLockStoreInterface
{
    public function pruneExpired(): void;
}
