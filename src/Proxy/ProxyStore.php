<?php

namespace JoeDixon\Translation\Proxy;

use PDO;

class ProxyStore
{
    private ?PDO $pdo;

    public function __construct(?string $path = null)
    {
        $path ??= self::defaultPath();

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->createSchema();
    }

    public static function defaultPath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $base = getenv('APPDATA') ?: (getenv('USERPROFILE') . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Roaming');
        } else {
            $base = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config');
        }

        return $base . DIRECTORY_SEPARATOR . 'laravel-translation' . DIRECTORY_SEPARATOR . 'proxies.sqlite';
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS proxies (
                url TEXT PRIMARY KEY,
                status TEXT NOT NULL DEFAULT \'unknown\',
                consecutive_failures INTEGER NOT NULL DEFAULT 0,
                response_ms INTEGER,
                last_tested_at TEXT
            )
        ');
    }

    /**
     * Insert new proxy URLs (ignores already-known proxies).
     *
     * @param  string[]  $urls
     */
    public function merge(array $urls): void
    {
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO proxies (url) VALUES (?)');
        $this->pdo->beginTransaction();
        foreach ($urls as $url) {
            $stmt->execute([$url]);
        }
        $this->pdo->commit();
    }

    /**
     * @param  string  $status  'unknown'|'working'|'dead'
     * @return string[]
     */
    public function getByStatus(string $status): array
    {
        $stmt = $this->pdo->prepare('SELECT url FROM proxies WHERE status = ? ORDER BY response_ms ASC NULLS LAST');
        $stmt->execute([$status]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM proxies WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    public function totalCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
    }

    public function hasWorking(): bool
    {
        return $this->countByStatus('working') > 0;
    }

    public function markWorking(string $url, int $responseMs): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE proxies
            SET status = \'working\', consecutive_failures = 0, response_ms = ?, last_tested_at = datetime(\'now\')
            WHERE url = ?
        ');
        $stmt->execute([$responseMs, $url]);
    }

    /**
     * Record a failure; mark dead after $failLimit consecutive failures.
     */
    public function recordFailure(string $url, int $failLimit = 3): void
    {
        $this->pdo->prepare('
            UPDATE proxies
            SET consecutive_failures = consecutive_failures + 1,
                last_tested_at = datetime(\'now\')
            WHERE url = ?
        ')->execute([$url]);

        // Separate statement avoids CASE WHEN ambiguity in SQLite
        $this->pdo->prepare('
            UPDATE proxies SET status = \'dead\'
            WHERE url = ? AND consecutive_failures >= ?
        ')->execute([$url, $failLimit]);
    }

    public function markDead(string $url): void
    {
        $stmt = $this->pdo->prepare('UPDATE proxies SET status = \'dead\', last_tested_at = datetime(\'now\') WHERE url = ?');
        $stmt->execute([$url]);
    }
}
