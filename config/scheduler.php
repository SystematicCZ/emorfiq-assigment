<?php

declare(strict_types=1);

return [
    // laravel | crunz
    'engine' => 'laravel',

    // Timezone for tasks that don't declare one; null = app.timezone.
    'timezone' => null,

    'locks' => [
        'store' => 'database',

        'stores' => [
            'database' => [
                // Always uses its own clone of the default DB connection (see LockConnection).
                'table' => 'scheduler_locks',
            ],

            // 'redis' => ['connection' => 'default'],   – a future RedisLockStore would be configured here
        ],
    ],
];
