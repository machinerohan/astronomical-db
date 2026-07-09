# AstroForum

Astronomy discussion forum with a community-curated catalogue of celestial objects.
Standard forum structure (categories → threads → replies) with nested proposal
sub-categories and structured catalogue-change proposals attached to replies.

## Quick Start

### Linux — Nix

```bash
nix develop

# Terminal 1: Start MariaDB
mysqld &

# Terminal 2: Load schema and seed data
mysql < db/schema.sql
mysql < db/seed.sql
mysql < htdocs/schema-forum.sql
mysql < htdocs/schema-forum-seed.sql

# Start PHP dev server
php -S localhost:8080 -t htdocs/
```

Visit http://localhost:8080/

### Windows — XAMPP

1. Install XAMPP, start **Apache** and **MySQL**
2. From the XAMPP shell:

```batch
mysql -u root < db\schema.sql
mysql -u root < db\seed.sql
mysql -u root < htdocs\schema-forum.sql
mysql -u root < htdocs\schema-forum-seed.sql
```

3. Copy `htdocs/` into `C:\xampp\htdocs\astroforum\`
4. Visit http://localhost/astroforum/

## Default Accounts

| Username | Password   | Role  | Expertise |
|----------|------------|-------|-----------|
| `admin`  | `admin`    | admin | verified  |
| `alice`  | `password` | member| normal    |

## Reference Syntax

| Syntax          | Links to                     |
|-----------------|------------------------------|
| `@username`     | User profile                 |
| `@entry:Sirius` | Catalogue entry              |
| `@thread:42`    | Another thread               |
| `@reply:123`    | A specific reply             |
