<?php

declare(strict_types=1);

namespace Domain\Lock\Exception;

final class LockLostException extends \RuntimeException implements LockException
{
    public function __construct(string $key, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Lock "%s" is no longer held by this owner (expired or taken over).', $key), previous: $previous);
    }
}
