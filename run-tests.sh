#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"

MYSQL_DATADIR=$(mktemp -d)
MYSQL_SOCK="$MYSQL_DATADIR/mysql.sock"

cleanup() {
    if [ -f "$MYSQL_SOCK" ]; then
        mysqladmin --socket="$MYSQL_SOCK" shutdown 2>/dev/null || true
    fi
    rm -rf "$MYSQL_DATADIR"
    rm -f phpunit.phar
}
trap cleanup EXIT

# Install & start MySQL
mysql_install_db --datadir="$MYSQL_DATADIR" --user=dietpi 2>/dev/null
mysqld --datadir="$MYSQL_DATADIR" --socket="$MYSQL_SOCK" --port=3307 --bind-address=127.0.0.1 --skip-grant-tables &
MYSQL_PID=$!

# Wait for MySQL to be ready
for i in $(seq 1 30); do
    if [ -S "$MYSQL_SOCK" ] && mysqladmin --socket="$MYSQL_SOCK" ping 2>/dev/null; then
        break
    fi
    sleep 1
done
if [ ! -S "$MYSQL_SOCK" ]; then
    echo "ERROR: MySQL did not start" >&2
    exit 1
fi

# Create and seed database
echo "Seeding database..."
mysql --socket="$MYSQL_SOCK" -u root <<<"DROP DATABASE IF EXISTS astronomical_db; CREATE DATABASE astronomical_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql --socket="$MYSQL_SOCK" -u root astronomical_db < db/schema.sql 2>/dev/null
mysql --socket="$MYSQL_SOCK" -u root astronomical_db < db/seed.sql 2>/dev/null
mysql --socket="$MYSQL_SOCK" -u root astronomical_db < htdocs/schema-forum.sql 2>/dev/null
mysql --socket="$MYSQL_SOCK" -u root astronomical_db < htdocs/schema-forum-seed.sql 2>/dev/null

# Download PHPUnit if needed
if [ ! -f phpunit.phar ]; then
    echo "Downloading PHPUnit..."
    wget -q -O phpunit.phar https://phar.phpunit.de/phpunit-11.phar
    chmod +x phpunit.phar
fi

# Run tests
echo "Running tests..."
ASTRO_DB_SOCK="$MYSQL_SOCK" php phpunit.phar --configuration phpunit.xml "$@"
