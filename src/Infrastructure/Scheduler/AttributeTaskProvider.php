<?php

declare(strict_types=1);

namespace Infrastructure\Scheduler;

use Application\Scheduler\TaskProviderInterface;
use Domain\Scheduler\CronExpression;
use Domain\Scheduler\Exception\InvalidTaskDefinition;
use Domain\Scheduler\ScheduledTask;
use Domain\Scheduler\TaskDefinition;

final readonly class AttributeTaskProvider implements TaskProviderInterface
{
    /** @param iterable<class-string> $classes */
    public function __construct(
        private iterable $classes,
        private \DateTimeZone $defaultTimezone,
    ) {
    }

    public function definitions(): iterable
    {
        foreach ($this->classes as $class) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getAttributes(ScheduledTask::class) as $attribute) {
                yield $this->toDefinition($reflection, $attribute->newInstance());
            }
        }
    }

    /** @param \ReflectionClass<object> $class */
    private function toDefinition(\ReflectionClass $class, ScheduledTask $attribute): TaskDefinition
    {
        return new TaskDefinition(
            cron: CronExpression::fromString($attribute->cron),
            taskClass: $class->getName(),
            timezone: $this->timezone($attribute->timezone, $class->getName()),
            withoutOverlapping: $attribute->withoutOverlapping,
            onOneServer: $attribute->onOneServer,
            lockTtl: $attribute->lockTtl,
            description: $attribute->description,
        );
    }

    private function timezone(?string $timezone, string $class): \DateTimeZone
    {
        if ($timezone === null) {
            return $this->defaultTimezone;
        }

        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidTaskDefinition(sprintf('class %s: unknown timezone "%s"', $class, $timezone));
        }
    }
}
