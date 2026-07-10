<?php

error_reporting(E_ALL & ~E_NOTICE);

$DB_SOCK   = getenv('ASTRO_DB_SOCK') ?: null;
$DB_HOST   = getenv('ASTRO_DB_HOST') ?: '127.0.0.1';
$DB_PORT   = getenv('ASTRO_DB_PORT') ?: '3306';
$DB_NAME   = getenv('ASTRO_DB_NAME') ?: 'astronomical_db';
$DB_USER   = getenv('ASTRO_DB_USER') ?: 'root';
$DB_PASS   = getenv('ASTRO_DB_PASS') ?: '';

$dsn = $DB_SOCK
    ? "mysql:unix_socket=$DB_SOCK;dbname=$DB_NAME;charset=utf8mb4"
    : "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";

try {
    $pdo = new PDO(
        $dsn,
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
