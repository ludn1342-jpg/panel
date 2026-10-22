<?php
// _db.php — Conexión PDO a PostgreSQL (Render)
if (defined('PYP_DB_LOADED')) return;
define('PYP_DB_LOADED', true);

function pyp_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = getenv('DATABASE_URL');

    if ($url) {
        // Render entrega: postgres://user:pass@host:port/dbname
        $p = parse_url($url);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            $p['host'] ?? 'localhost',
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '/postgres', '/')
        );
        $pdo = new PDO($dsn, $p['user'] ?? '', $p['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }

    // Fallback local (desarrollo)
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=prefer',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME') ?: 'pyp_solidario'
    );
    $pdo = new PDO($dsn,
        getenv('DB_USER') ?: 'postgres',
        getenv('DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}

function db_query(string $sql, array $p = []): PDOStatement {
    $st = pyp_db()->prepare($sql); $st->execute($p); return $st;
}
function db_fetch(string $sql, array $p = []): ?array {
    $r = db_query($sql, $p)->fetch();
    return $r === false ? null : $r;
}
function db_fetchAll(string $sql, array $p = []): array {
    return db_query($sql, $p)->fetchAll();
}
function db_exec(string $sql, array $p = []): int {
    return db_query($sql, $p)->rowCount();
}
