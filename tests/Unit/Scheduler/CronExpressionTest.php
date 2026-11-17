<?php

declare(strict_types=1);

use Domain\Scheduler\CronExpression;
use Domain\Scheduler\Exception\InvalidTaskDefinition;

it('accepts 5-field expressions, normalizing whitespace', function (string $input, string $expected): void {
    expect(CronExpression::fromString($input)->expression)->toBe($expected);
})->with([
    ['* * * * *', '* * * * *'],
    ['  */5   1-3 * JAN mon-fri ', '*/5 1-3 * JAN mon-fri'],
]);

it('rejects malformed cron shapes', function (string $input): void {
    CronExpression::fromString($input);
})->with(['', '* * * *', '* * * * * *', '* * * * ;rm', '@daily'])->throws(InvalidTaskDefinition::class);
