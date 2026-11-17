<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Tasks;

// Abstract – must be skipped by the class finder.
abstract class AbstractBaseTask
{
    abstract public function __invoke(): void;
}
