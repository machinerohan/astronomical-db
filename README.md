# Astronomical Objects Database

MySQL/MariaDB database of astronomical objects (stars, galaxies, nebulae, planets).

## Setup

### Windows — XAMPP

1. Install [XAMPP](https://www.apachefriends.org/), start **Apache** and **MySQL**.
2. From the XAMPP shell:

```batch
mysql -u root < db\schema.sql
mysql -u root < db\seed.sql
```

The `astronomical_db` database is now ready with the `objects` table and sample data.

### macOS — XAMPP

1. Install [XAMPP](https://www.apachefriends.org/), then open **XAMPP Manager**
	and start **MySQL**. Start **Apache** too if you want to use the PHP frontend.
2. From the project directory, load the schema and sample data using XAMPP's
	bundled MySQL client:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/schema.sql
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/seed.sql
```

The `astronomical_db` database is now ready with the `objects` table and sample
data. If you configured a root password, add `-p` to both commands.

### Linux — Nix

```bash
nix develop

# First time: loads schema
mysql < db/schema.sql
mysql < db/seed.sql

# Or start the server manually and connect:
mysqld &                          # start in background
mysql                             # connect (uses project socket)
mysqladmin shutdown               # stop the server
```

The Nix shell provides `mysql`, `mysqld`, and `mysqladmin` pre-configured
with a local data directory (`.mysql-data/`).

## Schema

Run `db/schema.sql` to create the `objects` table with columns for name,
catalog ID, object type, coordinates, magnitude, constellation, distance,
discovery details, and notes.
