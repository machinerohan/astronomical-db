<?php

declare(strict_types=1);

// Forum database (users, threads, posts, proposals, disputes)
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'astronomical_db';
const DB_USER = 'root';
const DB_PASS = '';

// Catalogue database (spec R12) — same server, separate schema.
const CATALOGUE_DB_NAME = 'catalogue_db';

function db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = connect(DB_NAME);

    return $connection;
}

/** Separate read/write handle for catalogue_db (spec R12). */
function catalog_db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = connect(CATALOGUE_DB_NAME);

    return $connection;
}

function connect(string $name): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $name . ';charset=utf8mb4';

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
