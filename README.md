# AstroForum

Astronomy discussion forum with a community-curated catalogue of celestial
objects. Standard forum structure (categories → threads → replies) with
nested proposal sub-categories and structured catalogue-change proposals
attached to replies.

## Prerequisites

- **Linux (Nix):** [Nix package manager](https://nixos.org/download) with
  flakes enabled. Everything is provided by the flake — no manual installs.
- **Windows:** [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP)
  with `pdo_mysql` enabled.

## Quick Start

### Linux — Nix

```bash
nix develop

# Start MariaDB in background
mysqld &

# Load schema and seed data
mysql < db/schema.sql
mysql < db/seed.sql
mysql < htdocs/schema-forum.sql
mysql < htdocs/schema-forum-seed.sql

# Start PHP dev server
php -S localhost:8080 -t htdocs/
```

Visit http://localhost:8080/

Stop with `mysqladmin shutdown` and Ctrl+C on the PHP server.

### Windows — XAMPP

1. Install XAMPP, start **Apache** and **MySQL** from the control panel
2. Open the XAMPP shell and run:

```batch
mysql -u root < db\schema.sql
mysql -u root < db\seed.sql
mysql -u root < htdocs\schema-forum.sql
mysql -u root < htdocs\schema-forum-seed.sql
```

3. Copy the `htdocs/` folder into `C:\xampp\htdocs\astroforum\`
4. Visit http://localhost/astroforum/

## Default Accounts

| Username | Password   | Role  | Expertise | Notes                        |
|----------|------------|-------|-----------|------------------------------|
| `admin`  | `admin`    | admin | verified  | Can manage users, categories |
| `alice`  | `password` | member| normal    | Regular forum member         |

Registration is admin-only. No public signup — ask an admin to create your account.

## Usage

- Browse categories from the **forum index** — each category lists its threads.
- Start a **new thread** in any category. Proposal sub-categories
  (`Stars — Proposals`, etc.) accept `add_entry`, `edit_field`, or
  `remove_entry` proposals with structured data forms.
- **Mark a solution** on identification threads when the object is identified.
  Use `@entry:Sirius` in your reply to auto-link the catalogue entry.
- **Approve/reject proposals** — expert, verified, and admin users see
  Approve/Reject buttons on pending proposal threads.
- **Admins** access the admin panel via the nav bar or `/admin/` to manage
  users, verify experts, and review contributions.
- **Reference syntax** works in all post bodies:

| Syntax          | Links to                     |
|-----------------|------------------------------|
| `@username`     | User profile                 |
| `@entry:Sirius` | Catalogue entry by name or ID|
| `@thread:42`    | Another thread               |
| `@reply:123`    | A specific reply             |

## Pre-seeded Catalogue

| Object    | Catalog ID | Type     |
|-----------|------------|----------|
| Sirius    | HR 2491    | star     |
| Andromeda | M31        | galaxy   |
| Orion     | M42        | nebula   |

## Project Structure

- `SPEC.md` — full design specification with database schema and page layout
- `db/schema.sql` — base catalogue table
- `db/seed.sql` — catalogue seed data
- `htdocs/schema-forum.sql` — forum tables
- `htdocs/schema-forum-seed.sql` — forum seed data
- `htdocs/` — PHP application
- `flake.nix` — Nix dev environment
- `.mysql-data/` — local MariaDB data directory (auto-created, gitignored)
