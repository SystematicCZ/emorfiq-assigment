<?php

declare(strict_types=1);

namespace Infrastructure\Lock;

use Domain\Lock\Exception\LockConflictedException;
use Domain\Lock\LockStoreInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

// Plain PDO on purpose (no Laravel/Doctrine), so the same store works under crunz.
// Atomicity: every write is ONE conditional statement on the primary key, so the DB lets exactly one competitor win.
// Expiry uses the app server's clock: servers sharing the store need NTP-synced clocks.
// Expired rows never block (save() takes them over); they are only garbage, deleted on a random share of saves.
final class PdoLockStore implements LockStoreInterface
{
    public const string DEFAULT_TABLE = 'scheduler_locks';

    public const float DEFAULT_GC_PROBABILITY = 0.01;

    private readonly string $table;

    private bool $isSetUp = false;

    // The table is created on first use (like auto_setup in Symfony Messenger's Doctrine transport), not by a migration.
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock,
        string $table = self::DEFAULT_TABLE,
        private readonly float $gcProbability = self::DEFAULT_GC_PROBABILITY,
    ) {
        if ($gcProbability < 0.0 || $gcProbability > 1.0) {
            throw new \InvalidArgumentException(sprintf('Lock GC probability must be between 0 and 1, %s given.', $gcProbability));
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid lock table name "%s".', $table));
        }

        $this->table = $table;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function setup(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                lock_key VARCHAR(191) NOT NULL PRIMARY KEY,
                token VARCHAR(64) NOT NULL,
                expires_at BIGINT NOT NULL
            )",
        );
        $this->isSetUp = true;
    }

    public function save(string $key, string $token, int $ttlSeconds): void
    {
        $this->ensureSetUp();

        $now = $this->now();
        $expiresAt = $now + $ttlSeconds;

        if (!$this->insertIfAbsent($key, $token, $expiresAt)) {
            $this->takeOver($key, $token, $expiresAt, $now);
        }

        // Like Symfony's PDO lock store: no cleanup job, the store deletes expired rows on a random share of saves.
        if (new Randomizer()->getFloat(0, 1) < $this->gcProbability) {
            $this->prune($now);
        }
    }

    private function takeOver(string $key, string $token, int $expiresAt, int $now): void
    {
        // Row exists – write it only if it is already ours (re-acquire) or expired (abandoned by a crashed owner).
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET token = :token, expires_at = :expires_at
             WHERE lock_key = :key AND (token = :owner OR expires_at <= :now)",
        );
        $stmt->execute(['token' => $token, 'expires_at' => $expiresAt, 'key' => $key, 'owner' => $token, 'now' => $now]);

        $this->assertWritten($stmt, $key);
    }

    public function refresh(string $key, string $token, int $ttlSeconds): void
    {
        $this->ensureSetUp();

        $now = $this->now();
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET expires_at = :expires_at
             WHERE lock_key = :key AND token = :token AND expires_at > :now",
        );
        $stmt->execute(['expires_at' => $now + $ttlSeconds, 'key' => $key, 'token' => $token, 'now' => $now]);

        $this->assertWritten($stmt, $key);
    }

    public function delete(string $key, string $token): void
    {
        $this->ensureSetUp();

        $this->pdo->prepare("DELETE FROM {$this->table} WHERE lock_key = :key AND token = :token")
            ->execute(['key' => $key, 'token' => $token]);
    }

    public function exists(string $key, ?string $token = null): bool
    {
        $this->ensureSetUp();

        $sql = "SELECT 1 FROM {$this->table} WHERE lock_key = :key AND expires_at > :now";
        $params = ['key' => $key, 'now' => $this->now()];

        if ($token !== null) {
            $sql .= ' AND token = :token';
            $params['token'] = $token;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    private function prune(int $now): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at <= :now")->execute(['now' => $now]);
    }

    private function ensureSetUp(): void
    {
        if (!$this->isSetUp) {
            $this->setup();
        }
    }

    private function assertWritten(\PDOStatement $stmt, string $key): void
    {
        if ($stmt->rowCount() === 0) {
            throw new LockConflictedException($key);
        }
    }

    private function insertIfAbsent(string $key, string $token, int $expiresAt): bool
    {
        $sql = match ($driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "INSERT OR IGNORE INTO {$this->table} (lock_key, token, expires_at) VALUES (:key, :token, :expires_at)",
            // mysql/postgres/mariadb specific implementation would go here
            default => throw new \LogicException(sprintf('PDO driver "%s" is not supported by %s.', is_string($driver) ? $driver : get_debug_type($driver), self::class)),
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['key' => $key, 'token' => $token, 'expires_at' => $expiresAt]);

        return $stmt->rowCount() === 1;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
