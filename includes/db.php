<?php
/**
 * Database Connection (PDO)
 */
require_once __DIR__ . '/../config.php';

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Sync MySQL session timezone with PHP's configured timezone so
            // that NOW() in queries matches date() in PHP (fixes schedule timing).
            $offsetSecs = (new DateTimeZone(APP_TIMEZONE))->getOffset(new DateTime());
            $sign       = $offsetSecs >= 0 ? '+' : '-';
            $hours      = str_pad(floor(abs($offsetSecs) / 3600), 2, '0', STR_PAD_LEFT);
            $minutes    = str_pad(floor((abs($offsetSecs) % 3600) / 60), 2, '0', STR_PAD_LEFT);
            $pdo->exec("SET time_zone = '{$sign}{$hours}:{$minutes}'");
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

/**
 * Execute a query and return all results
 */
function dbFetchAll($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Execute a query and return one row
 */
function dbFetchOne($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

/**
 * Execute a query and return the row count
 */
function dbExecute($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Insert and return last insert ID
 */
function dbInsert($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return getDB()->lastInsertId();
}

/**
 * Get a single value
 */
function dbFetchValue($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
