# AstroForum Deployment Guide

Complete step-by-step guide to deploy the Astronomical Objects Database & Forum on XAMPP.

## Prerequisites

- **XAMPP** installed (Apache + MySQL + PHP)
  - Download: https://www.apachefriends.org/
- PHP 7.4+ required (included with XAMPP)
- **Windows Command Prompt** or **Terminal** (macOS/Linux)
- Project files in `c:\xampp\htdocs\astronomical-db\` (Windows) or equivalent

## Step 1: Start XAMPP Services

### Windows
1. Open **XAMPP Control Panel**
2. Click **Start** next to "Apache"
3. Click **Start** next to "MySQL"
4. Wait for both to show as running (green status)

### macOS
1. Open **XAMPP** application
2. Click **Manage Servers** tab
3. Start **Apache Web Server**
4. Start **MySQL Database**

### Linux
```bash
sudo /opt/lampp/manager-linux-x64.run
# Or use command line:
sudo /opt/lampp/bin/mysql.server start
sudo /opt/lampp/bin/apache2ctl start
```

## Step 2: Initialize Databases

Open **XAMPP Shell** (or terminal):

### Windows
```batch
cd c:\xampp\htdocs\astronomical-db

# Create databases and tables
mysql -u root < db\schema.sql
mysql -u root < db\schema-forum.sql

# Load initial data
mysql -u root < db\seed.sql
```

### macOS
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/astronomical-db

/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/schema.sql
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/schema-forum.sql
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/seed.sql
```

### With MySQL Password
If you configured a root password during XAMPP setup:
```bash
mysql -u root -p < db\schema.sql
# Then enter your password when prompted
```

### Verify Installation
```bash
mysql -u root
# At MySQL prompt:
SHOW DATABASES;
USE astronomical_catalogue;
SHOW TABLES;
USE astronomical_forum;
SHOW TABLES;
EXIT;
```

You should see these tables:
- `objects` (from schema.sql)
- `users`, `categories`, `threads`, `posts`, `proposals`, etc. (from schema-forum.sql)

## Step 3: Configure Database Connection (if needed)

Edit `web/config.php` if your MySQL setup differs:

```php
const DB_HOST = '127.0.0.1';    // Usually 127.0.0.1 or localhost
const DB_PORT = '3306';         // Default MySQL port
const DB_NAME = 'astronomical_forum';
const CATALOGUE_DB_NAME = 'astronomical_catalogue';
const DB_USER = 'root';         // XAMPP default user
const DB_PASS = '';             // Add password if you set one
```

## Step 4: Create First Admin Account

Open your browser and navigate to:
```
http://localhost/astronomical-db/web/setup.php
```

You'll see the admin setup form. Fill in:
- **Admin username**: (e.g., `admin`, `moderator`)
- **Admin password**: (at least 8 characters)

Click **Create administrator**. You should see: "Administrator created."

⚠️ **Note**: This page only works if no admin exists yet. Refresh if it says "An administrator already exists."

## Step 5: Access the Application

Navigate to the main application:
```
http://localhost/astronomical-db/web/
```

You should see the home page with:
- Hero section with "Ask questions. Share what you know."
- Browse objects section showing 3 sample objects (Sirius, Andromeda, Orion)
- Option to register or log in

## Step 6: Test the Complete Workflow

### Test 1: Register a Regular User

1. Click **Register**
2. Fill in:
   - Username: `testuser1`
   - Password: `password123456`
   - Confirm: `password123456`
3. Click **Register**
4. You should see: "Registration received. An administrator must approve your account..."

### Test 2: Admin Approves Registration

1. In a new tab, go to: `http://localhost/astronomical-db/web/index.php?page=login`
2. Log in with your admin account (created in Step 4)
3. You'll be redirected to Dashboard
4. Click **Admin desk** in the navigation
5. You should see the pending registration for `testuser1`
6. Click **Approve**
7. You should see: "Registration approved."

### Test 3: Regular User Logs In

1. Return to the login page
2. Log in as `testuser1` with password `password123456`
3. Click **Log in**
4. You'll be redirected to Dashboard showing your profile

### Test 4: Create a Forum Thread

1. Click **Forum** in the navigation
2. You'll see discussion categories:
   - General Discussion
   - Stars
   - Galaxies
   - Nebulae
   - Planets & Moons
   - Unidentified Objects
3. Click on **Stars** category
4. You'll see a form to create a thread
5. Fill in:
   - Thread title: `About Sirius`
   - Thread type: `Discussion`
   - Message: `Sirius is the brightest star in the night sky. Let's discuss its properties.`
6. Click **Create thread**
7. You'll see your thread created with your post

### Test 5: Reply to a Thread

1. On the thread page, scroll down to "Reply to this thread"
2. Add a reply: `Sirius has an apparent magnitude of -1.46, making it very bright!`
3. Click **Post reply**
4. Your reply appears below the original post

### Test 6: Create a Proposal

1. Go back to **Forum**
2. Click **Stars** (or any category)
3. Create a new thread with:
   - Type: **Propose new object**
   - Title: `New discovery in Canis Minor`
   - Message: `I'd like to add information about this newly discovered binary star`
4. Click **Create thread**

### Test 7: Expert Reviews Proposals (Admin Account)

1. Log in as the admin account (use private/incognito window or log out first)
2. Click **Approvals** in the navigation
3. You'll see pending proposals
4. Click **Approve** to approve the proposal
5. Or enter a reason and click **Reject** to reject it

### Test 8: View User Profile

1. In any thread, click on a username
2. You'll see their profile page with:
   - Expertise level
   - Statistics (Posts, Proposals, Approvals)
   - Member since date
   - Recent activity

### Test 9: Check Your Dashboard

1. Click **Dashboard**
2. You'll see:
   - Your expertise level
   - Stats (posts created, proposals, approvals by you)
   - Account details
   - Recent activity

## Common Issues & Solutions

### Issue: "Access denied for user 'root'@'localhost'"

**Solution**: 
- MySQL isn't running. Start it in XAMPP Control Panel
- Check your password in `config.php`
- Try: `mysql -u root -p` to test connection manually

### Issue: "SQLSTATE[HY000]: General error: 1030"

**Solution**:
- Database may not be initialized
- Re-run: `mysql -u root < db\schema.sql`
- Make sure both `astronomical_catalogue` and `astronomical_forum` databases exist

### Issue: White screen after login

**Solution**:
- Check PHP error log: `xampp/php/logs/php_error.log`
- Verify `config.php` database credentials
- Ensure database tables exist in both databases: `USE astronomical_catalogue; SHOW TABLES;` and `USE astronomical_forum; SHOW TABLES;`

### Issue: "The page you are looking for cannot be found"

**Solution**:
- Verify URL: `http://localhost/astronomical-db/web/` (note the `/web/` path)
- Check XAMPP is running
- Verify files are in correct directory

### Issue: Can't create admin on setup.php

**Solution**:
- Admin already exists (only one can be created)
- Refresh the page with `Ctrl+Shift+Delete` to clear browser cache
- If you need to reset, delete the user: `mysql -u root -e "DELETE FROM astronomical_forum.users WHERE role='admin';"`

### Issue: Images/CSS not loading (page looks broken)

**Solution**:
- Styles use Google Fonts via CDN (should work with internet)
- If offline, comment out `@import url(...)` in `style.css`

## Database Backup

### Export Current Database

```bash
mysqldump -u root astronomical_catalogue > catalogue-backup.sql
mysqldump -u root astronomical_forum > forum-backup.sql
```

### Restore from Backup

```bash
mysql -u root astronomical_catalogue < catalogue-backup.sql
mysql -u root astronomical_forum < forum-backup.sql
```

## Performance Notes

- Application uses PDO with prepared statements (secure)
- No caching layer - suitable for development/small deployments
- For production: add Redis/Memcached for sessions and queries
- Database indexes created on: username, category_id, author_id, status fields

## Development Tips

### View PHP Errors

Add to `index.php` after `<?php`:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Test SQL Queries

```bash
mysql -u root
USE astronomical_forum;

# View all users
SELECT id, username, role, expertise FROM users;

# View all threads
SELECT id, title, type, status FROM threads;

# View all proposals
SELECT id, type, status, author_id FROM proposals;

# Exit
EXIT;
```

### Debug Mode

Add to `functions.php` to log database queries:
```php
function log_query($query) {
    error_log("Query: " . $query . "\n");
}
```

## Next: Feature Expansion Ideas

Once the basic system is working:

1. **Picture Uploads**: Add image upload for proposals
2. **Email Notifications**: Notify users on proposal decisions
3. **Search**: Full-text search for objects and discussions
4. **Tags**: Add tags to threads for better categorization
5. **Reputation System**: Auto-promote experts based on approval success rate
6. **API**: Build REST API for external integrations
7. **Mobile App**: Native app using the API
8. **Export**: Export catalogue as CSV/JSON

## Support

- Check `README.md` for general features
- Review `SPEC.md` for complete specification
- Check database schema in `DEPLOYMENT.md` (this file)
- Database diagrams: `astroforum-schema.drawio`, `astroforum_eer.drawio`

---

**Deployment Date**: 2026-08-27  
**Version**: 1.0  
**Status**: Production Ready
