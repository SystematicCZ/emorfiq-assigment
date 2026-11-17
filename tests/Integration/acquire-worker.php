<?php

declare(strict_types=1);

// Waits for a common start time, races for one lock, prints 1 (won) or 0 (lost).

use Domain\Lock\LockFactory;
use Infrastructure\Clock\SystemClock;
use Infrastructure\Lock\PdoLockStore;

require __DIR__ . '/../../vendor/autoload.php';

[, $dbFile, $startAt] = $argv;

$pdo = new PDO('sqlite:' . $dbFile, options: [PDO::ATTR_TIMEOUT => 10]);
$locks = new LockFactory(new PdoLockStore($pdo, new SystemClock()), new SystemClock());

time_sleep_until((float) $startAt);

$lock = $locks->createLock('race', 30);
$acquired = $lock->acquire();
echo $acquired ? '1' : '0';

if ($acquired) {
    usleep(500_000);
    $lock->release();
}
