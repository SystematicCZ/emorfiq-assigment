<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Tasks;

final class SpyTask
{
    public int $calls = 0;

    /** @var (\Closure(): void)|null */
    public ?\Closure $onRun = null;

    public function __invoke(): void
    {
        ++$this->calls;

        if ($this->onRun !== null) {
            ($this->onRun)();
        }
    }
}
