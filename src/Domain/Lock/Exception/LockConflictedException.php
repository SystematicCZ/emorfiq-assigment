<?php

declare(strict_types=1);

namespace Domain\Lock\Exception;

final class LockConflictedException extends \RuntimeException implements LockException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Lock "%s" is held by another owner.', $key));
    }
}
