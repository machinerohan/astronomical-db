# AstroForum Feature 0

This is a small PHP frontend for the existing `astronomical_db` MySQL/MariaDB database.

## XAMPP setup

1. Copy this `web` directory into `/Applications/XAMPP/htdocs/astroforum`.
2. Open `http://localhost/astroforum/setup.php` once and create the first administrator.
3. Register a member at `http://localhost/astroforum/index.php?page=register`.
4. Log in as the administrator and approve the pending registration from **Admin desk**.
5. Log in as the approved member.

The app uses PDO prepared statements, password hashing, sessions, and CSRF tokens. Remove or protect `setup.php` after the first administrator is created.