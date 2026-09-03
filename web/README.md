# AstroForum Feature 0

This is a small PHP frontend for the `astronomical_forum` and `astronomical_catalogue` MySQL/MariaDB databases.

## XAMPP setup

1. Copy the complete project directory into `/Applications/XAMPP/htdocs/astronomical-db`.
2. Import `db/schema.sql`, `db/schema-forum.sql`, and `db/seed.sql` using the XAMPP MySQL client or phpMyAdmin.
3. Open `http://localhost/astronomical-db/web/setup.php` once and create the first administrator.
4. Register a member at `http://localhost/astronomical-db/web/index.php?page=register`.
5. Log in as the administrator and approve the pending registration from **Admin desk**.
6. Log in as the approved member.

The app uses PDO prepared statements, password hashing, sessions, and CSRF tokens. Remove or protect `setup.php` after the first administrator is created.
