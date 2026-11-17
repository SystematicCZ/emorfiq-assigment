<?php

declare(strict_types=1);

namespace Tests\Support;

use Application\Scheduler\TaskProviderInterface;
use Domain\Scheduler\TaskDefinition;

final readonly class ArrayTaskProvider implements TaskProviderInterface
{
    /** @param list<TaskDefinition> $definitions */
    public function __construct(private array $definitions)
    {
    }

    public function definitions(): iterable
    {
        return $this->definitions;
    }
}
