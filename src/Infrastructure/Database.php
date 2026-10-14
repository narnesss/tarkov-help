<?php
declare(strict_types=1);
namespace EftWiki\Infrastructure;

final class Database
{
    public readonly \PDO $pdo;
    public function __construct(string $path)
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
        $this->migrate();
        if ($path !== ':memory:') { chmod($path, 0600); }
    }

    private function migrate(): void
    {
        if ((int) $this->pdo->query('PRAGMA user_version')->fetchColumn() >= 1) { return; }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, username TEXT UNIQUE, password_hash TEXT, created_at INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), expires_at INTEGER NOT NULL);
                CREATE INDEX IF NOT EXISTS sessions_expiry ON sessions(expires_at);
                CREATE TABLE IF NOT EXISTS user_state (user_id TEXT NOT NULL REFERENCES users(id), state_key TEXT NOT NULL, value TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL, PRIMARY KEY(user_id, state_key));
                CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, expires_at INTEGER NOT NULL);
                PRAGMA user_version=1;');
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}
