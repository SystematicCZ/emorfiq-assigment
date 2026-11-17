<?php

declare(strict_types=1);

namespace Infrastructure\Laravel;

use Illuminate\Contracts\Config\Repository;

// The lock store always gets its own clone of the default DB connection, by design: a lock row written inside
// a transaction the application has open would be invisible to others until commit and vanish on rollback.
final class LockConnection
{
    public const string DEDICATED = 'scheduler_locks';

    public static function name(Repository $config): string
    {
        $default = $config->string('database.default');
        $settings = $config->array("database.connections.{$default}");

        // SQLite :memory: (tests): a second connection would be a different, empty database.
        if (($settings['driver'] ?? null) === 'sqlite' && ($settings['database'] ?? null) === ':memory:') {
            return $default;
        }

        if ($config->get('database.connections.' . self::DEDICATED) === null) {
            $config->set('database.connections.' . self::DEDICATED, $settings);
        }

        return self::DEDICATED;
    }
}
