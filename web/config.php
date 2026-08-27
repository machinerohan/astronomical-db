<?php

declare(strict_types=1);

// Forum database (users, threads, posts, proposals, disputes).
// Every value can be overridden through environment variables, e.g. for a
// dedicated app user: ASTRO_DB_USER=astro ASTRO_DB_PASS=secret php -S ...

function astro_db_config(): array
{
    return [
        'host'      => getenv('ASTRO_DB_HOST') ?: '127.0.0.1',
        'port'      => getenv('ASTRO_DB_PORT') ?: '3306',
        'name'      => getenv('ASTRO_DB_NAME') ?: 'astronomical_db',
        'catalogue' => getenv('ASTRO_CATALOGUE_DB') ?: 'catalogue_db',
        'user'      => getenv('ASTRO_DB_USER') ?: 'root',
        // Empty password by default (XAMPP convention); ASTRO_DB_PASS='' keeps it empty.
        'pass'      => getenv('ASTRO_DB_PASS') === false ? '' : getenv('ASTRO_DB_PASS'),
    ];
}

function connect(string $name): PDO
{
    $cfg = astro_db_config();
    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $name . ';charset=utf8mb4';

    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = connect(astro_db_config()['name']);

    return $connection;
}

/** Separate handle for the catalogue database (spec R12). */
function catalog_db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = connect(astro_db_config()['catalogue']);

    return $connection;
}

