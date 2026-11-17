<?php

declare(strict_types=1);

return [
    // laravel | crunz
    'engine' => env('SCHEDULER_ENGINE', 'laravel'),

    // Timezone for tasks that don't declare one; null = app.timezone.
    'timezone' => env('SCHEDULER_TIMEZONE'),

    'locks' => [
        'store' => env('SCHEDULER_LOCK_STORE', 'database'),

        'stores' => [
            'database' => [
                // Always uses its own clone of the default DB connection (see LockConnection).
                'table' => 'scheduler_locks',
                // Creates the table on first use; set false where the DB user can't run DDL and create it in a deploy step.
                'auto_setup' => env('SCHEDULER_LOCK_AUTO_SETUP', true),
            ],

            // 'redis' => ['connection' => 'default'],   – a future RedisLockStore would be configured here
        ],
    ],
];
