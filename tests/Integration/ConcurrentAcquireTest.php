<?php

declare(strict_types=1);

use Infrastructure\Clock\SystemClock;
use Infrastructure\Lock\PdoLockStore;

it('lets exactly one of many concurrent processes acquire the lock', function (): void {
    $dbFile = tempnam(sys_get_temp_dir(), 'locks_') . '.sqlite';
    $pdo = new PDO('sqlite:' . $dbFile);
    // WAL is persisted in the file. Setting it here, once, avoids a race: workers switching the journal mode
    // concurrently can fail with "database is locked" regardless of the busy timeout.
    $pdo->exec('PRAGMA journal_mode=WAL');
    (new PdoLockStore($pdo, new SystemClock()))->setup();

    $workers = 12;
    $startAt = microtime(true) + 1.0;
    $processes = [];
    for ($i = 0; $i < $workers; ++$i) {
        $processes[] = proc_open(
            [PHP_BINARY, __DIR__ . '/acquire-worker.php', $dbFile, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $outputs[$i] = $pipes;
    }

    $results = [];
    foreach ($processes as $i => $process) {
        $results[] = trim(stream_get_contents($outputs[$i][1]));
        $stderr = stream_get_contents($outputs[$i][2]);
        expect(proc_close($process))->toBe(0, $stderr);
    }

    @unlink($dbFile);

    $counts = array_count_values($results);
    ksort($counts);

    expect($counts)->toBe([0 => $workers - 1, 1 => 1]);
});
