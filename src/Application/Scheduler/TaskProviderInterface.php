<?php

declare(strict_types=1);

namespace Application\Scheduler;

use Domain\Scheduler\TaskDefinition;

interface TaskProviderInterface
{
    /** @return iterable<TaskDefinition> */
    public function definitions(): iterable;
}
