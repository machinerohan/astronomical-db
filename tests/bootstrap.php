<?php

error_reporting(E_ALL);

// Include app source files (not db.php — we manage PDO in tests)
require_once __DIR__ . '/../htdocs/includes/auth.php';
require_once __DIR__ . '/../htdocs/includes/functions.php';

// Constants are defined in functions.php via require_once

// Register PSR-4-like autoloading for Tests\ namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Start session for auth tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Create a fresh PDO connection to the test database.
 */
function test_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $sock = getenv('ASTRO_DB_SOCK') ?: null;
        $host = getenv('ASTRO_DB_HOST') ?: '127.0.0.1';
        $port = getenv('ASTRO_DB_PORT') ?: '3306';
        $dsn = $sock
            ? "mysql:unix_socket=$sock;dbname=astronomical_db;charset=utf8mb4"
            : "mysql:host=$host;port=$port;dbname=astronomical_db;charset=utf8mb4";
        $pdo = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
