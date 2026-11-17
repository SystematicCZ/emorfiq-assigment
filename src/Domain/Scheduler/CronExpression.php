<?php

declare(strict_types=1);

namespace Domain\Scheduler;

use Domain\Scheduler\Exception\InvalidTaskDefinition;

// Checks only the shape (5 fields). Whether e.g. "61 * * * *" is valid is decided by CronValidatorInterface,
// so no cron library leaks into the domain.
final readonly class CronExpression implements \Stringable
{
    private function __construct(public string $expression)
    {
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    public static function fromString(string $expression): self
    {
        $normalized = (preg_replace('/\s+/', ' ', $expression) ?? $expression) |> trim(...);

        $fields = explode(' ', $normalized);
        if (count($fields) !== 5) {
            throw new InvalidTaskDefinition(
                sprintf(
                    'cron expression "%s" must have exactly 5 fields (minute hour day-of-month month day-of-week)',
                    $expression,
                ),
            );
        }

        foreach ($fields as $field) {
            if (preg_match('/^[0-9A-Za-z*,\/\-?LW#]+$/', $field) !== 1) {
                throw new InvalidTaskDefinition(
                    sprintf('cron expression "%s" contains an invalid field "%s"', $expression, $field),
                );
            }
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->expression === $other->expression;
    }
}
