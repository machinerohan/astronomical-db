# AstroForum — Astronomical Objects Database & Community Catalogue

A StackOverflow-like forum layered on a crowdsourced astronomical catalogue,
implementing `SPEC.md`. Two MySQL/MariaDB databases on one server:

| Database | Contents | Loaded by |
|---|---|---|
| `catalogue_db` | astronomical objects, observer pictures | `db/schema.sql` |
| `astronomical_db` | users, categories/subforums, threads, posts, proposals, disputes, verifications | `db/schema-forum.sql` |

Forum rows reference catalogue objects by plain integer columns — cross-database
foreign keys are not portable, so integrity is enforced by `web/functions.php`.

## Setup

### Windows / macOS — XAMPP

1. Install [XAMPP](https://www.apachefriends.org/), start **Apache** and **MySQL**.
2. Load both databases from the XAMPP shell (repo root):

```batch
mysql -u root < db\schema.sql
mysql -u root < db\schema-forum.sql
mysql -u root < db\seed.sql
```

3. Copy the `web` directory into XAMPP's htdocs (`C:\xampp\htdocs\astroforum`
   or `/Applications/XAMPP/htdocs/astroforum`).
4. Open `http://localhost/astroforum/setup.php` once to create the first
   administrator. Remove or protect `setup.php` afterwards.
5. Register a member, approve them via **Admin desk**, and start posting.

### Linux — clone and serve (Ubuntu/Debian)

```bash
git clone -b forum-core https://github.com/machinerohan/astronomical-db.git
cd astronomical-db

sudo service mysql start                      # or: sudo systemctl start mysql

sudo mysql -e "DROP DATABASE IF EXISTS astronomical_db; DROP DATABASE IF EXISTS catalogue_db;"

sudo mysql < db/schema.sql
sudo mysql < db/schema-forum.sql
sudo mysql < db/seed.sql
```

The schema files are safe to re-run — nothing fails with
`trigger already exists` a second time.

#### First run on stock Ubuntu/Debian MySQL

Ubuntu's MySQL only accepts `root` through `sudo` (unix_socket auth), which the
PHP app cannot use over TCP. Create an application user instead of weakening
root:

```bash
sudo mysql <<'SQL'
CREATE USER IF NOT EXISTS astro@localhost IDENTIFIED BY 'astrodemo';
GRANT ALL ON astronomical_db.* TO astro@localhost;
GRANT ALL ON catalogue_db.* TO astro@localhost;
FLUSH PRIVILEGES;
SQL

ASTRO_DB_USER=astro ASTRO_DB_PASS=astrodemo php -S 127.0.0.1:8080 -t web/
```

Open **http://127.0.0.1:8080/setup.php** once to create the first
administrator, then start using the forum. Remove or protect `setup.php`
afterwards on any shared host.

> Alternative (XAMPP-style, empty root password over TCP): run
> `sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('')"`
> and then plain `php -S 127.0.0.1:8080 -t web/` without environment variables.

### Database credentials

`web/config.php` defaults to `127.0.0.1:3306`, user `root`, empty password,
databases `astronomical_db` + `catalogue_db`. Every value can be overridden via
environment variables — useful for anything that is not XAMPP:

| Variable | Default |
|---|---|
| `ASTRO_DB_HOST` | `127.0.0.1` |
| `ASTRO_DB_PORT` | `3306` |
| `ASTRO_DB_USER` | `root` |
| `ASTRO_DB_PASS` | *(empty)* |
| `ASTRO_DB_NAME` | `astronomical_db` |
| `ASTRO_CATALOGUE_DB` | `catalogue_db` |

### Nix development shell

```bash
nix develop
mysql < db/schema.sql && mysql < db/schema-forum.sql && mysql < db/seed.sql
php -S 127.0.0.1:8080 -t web/
```

### Features (mapped to SPEC.md)

1. Admin approval of registrations — pending queue in **Admin desk**.
2. Discussion & identification threads in general/object-type subforums.
3. Identification may only be requested in an identification thread's opening
   message; only the author confirms, linking the thread to a catalogue entry.
4. Proposal threads add entries (`add_entry`) or edit fields (`edit_field`),
   with optional attached pictures.
5. Experts/verified/admins approve or reject proposals; **rejections must carry
   a reason posted as a reply**; approval applies the change to `catalogue_db`,
   records it in `object_edits`, and auto-promotes authors after 3 approvals.
6. Approved proposals can be disputed; anyone except the original approver can
   resolve — upholding reverts the change (or removes the created object).
7. Two upheld reverts against a member's proposals strip their auto-granted
   expert standing.
8. Admins verify users (equal rights to experts) with mandatory recorded reason;
   admins restrict/unrestrict verified/expert members; every action is logged in
   `verifications`.
9. Subforums per object type (categories seeded); approval/rejection/dispute
   outcomes all post reply messages back into the proposal thread, linked to
   posts or catalogue entries.
10. Profile pages show standing stats and post/proposal/dispute history.
11. Pictures attach to proposals, then move onto the catalogue entry on
    approval; object pages and cards display them.
12. Catalogue lives in its own database (`R12`).

Tuning constants live at the top of `web/functions.php`
(`EXPERT_PROMOTION_THRESHOLD = 3`, `REVERTS_BEFORE_EXPERT_LOSS = 2`).
