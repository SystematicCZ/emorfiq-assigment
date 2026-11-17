<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Tasks;

// Its parent class does not exist, so it cannot be loaded – the class finder must skip it, not crash.
final class BrokenTask extends \Tests\Support\Fixtures\DoesNotExist
{
    public function __invoke(): void
    {
    }
}
