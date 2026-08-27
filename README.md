# AstroForum - Astronomical Objects Database & Community Forum

A comprehensive StackOverflow-like forum for discussing and cataloging astronomical objects. Features user registration, expert-based proposal system, and a collaborative community for astronomy enthusiasts.

## Features

- **User Registration & Management**: Admin-approved registration with expertise levels (normal, expert, verified)
- **Forum System**: Categorized discussions by object type (stars, galaxies, nebulae, etc.)
- **Discussion Types**:
  - General discussions
  - Object identification help threads
  - Proposals for new objects
- **Proposal System**: 
  - Add new objects or edit existing ones
  - Expert approval workflow
  - Dispute resolution mechanism
- **User Profiles**: Activity history, statistics, and reputation tracking
- **Admin Dashboard**: Registration approval and moderation tools
- **Expert Tools**: Review and approve/reject proposals to the catalogue

## Quick Setup — XAMPP (Windows)

### 1. Prerequisites
- [XAMPP](https://www.apachefriends.org/) installed with Apache and MySQL running
- `htdocs` folder accessible

### 2. Clone/Copy Project
Copy the project into your XAMPP htdocs folder:
```
c:\xampp\htdocs\astronomical-db\
```

### 3. Initialize Database

From **XAMPP shell** (cmd in the project directory):

```batch
cd c:\xampp\htdocs\astronomical-db
mysql -u root < db\schema.sql
mysql -u root < db\schema-forum.sql
mysql -u root < db\seed.sql
```

**Note**: If MySQL has a password, add `-p` and enter it when prompted:
```batch
mysql -u root -p < db\schema.sql
```

### 4. Start Services
1. Open **XAMPP Control Panel**
2. Start **Apache** and **MySQL**
3. Navigate to: `http://localhost/astronomical-db/web/setup.php`
4. Create the first admin account

### 5. Access the Application
- Main app: `http://localhost/astronomical-db/web/`
- Admin desk: `http://localhost/astronomical-db/web/index.php?page=admin` (after logging in as admin)
- Forum: `http://localhost/astronomical-db/web/index.php?page=forum`

## Setup — macOS (XAMPP)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/astronomical-db

# Initialize databases
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/schema.sql
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/schema-forum.sql
/Applications/XAMPP/xamppfiles/bin/mysql -u root < db/seed.sql

# If you set a MySQL password, add -p before entering it
```

Then open **XAMPP Manager** and start Apache and MySQL. Navigate to `http://localhost/astronomical-db/web/setup.php`

## Setup — Linux (Nix)

```bash
cd ~/astronomical-db
nix develop

# Start the database server in background
mysqld --datadir=.mysql-data --socket=.mysql.sock &

# Load schemas and data
mysql --socket=.mysql.sock < db/schema.sql
mysql --socket=.mysql.sock < db/schema-forum.sql
mysql --socket=.mysql.sock < db/seed.sql

# Shutdown when done
mysqladmin --socket=.mysql.sock shutdown
```

## Database Schema

The application uses **two databases** within `astronomical_db`:

### Catalogue Tables (schema.sql)
- `objects` - Astronomical objects (stars, galaxies, nebulae, etc.)

### Forum Tables (schema-forum.sql)

**Users & Permissions**
- `users` - User accounts with roles (admin/member) and expertise levels
- `verifications` - Manual verification records by admins

**Forum Structure**
- `categories` - Discussion categories (Stars, Galaxies, General, etc.)
- `threads` - Discussion threads (discussion, identification, proposal types)
- `posts` - Individual posts/replies in threads

**Proposal System**
- `proposals` - Proposed changes to the catalogue
- `proposed_objects` - Full object data for "add new object" proposals
- `object_edits` - History of applied edits
- `disputes` - Challenges to approved proposals

## User Roles & Expertise

### Roles
- **Admin**: System administration, registration approval, manual verification
- **Member**: Regular user

### Expertise Levels
- **Normal**: Regular member, can post and create proposals
- **Expert**: Can approve proposals (earned through good proposal history)
- **Verified**: Manually verified by admin, can approve proposals
- **Restricted**: Cannot create proposals (temporary status after disputes)

## Workflow Examples

### Creating an Object Proposal

1. **Register & Login**
   - Register for an account
   - Wait for admin approval
   - Log in

2. **Create Proposal Thread**
   - Go to Forum > appropriate category
   - Create new thread with type "Propose new object"
   - Fill in object details (name, type, coordinates, constellation, etc.)

3. **Expert Review**
   - Experts/Admins go to "Approvals" tab
   - Review the proposal
   - Approve (adds to catalogue) or Reject (with reason)

### Identifying an Unknown Object

1. **Create Identification Thread**
   - Go to Forum > Unidentified Objects
   - Create thread with type "Help identifying object"
   - Describe the object

2. **Community Discussion**
   - Other users reply with suggestions
   - OP (original poster) confirms when object is identified

### Editing an Object

1. **Create Edit Proposal**
   - Find the object in a thread or catalogue
   - Create proposal to edit a specific field
   - Specify new value

2. **Expert Approval**
   - Expert reviews and approves
   - Change is applied to object in catalogue

## Project Structure

```
astronomical-db/
├── db/
│   ├── schema.sql            # Catalogue database schema
│   ├── schema-forum.sql      # Forum database schema
│   └── seed.sql              # Initial data (objects & categories)
├── web/
│   ├── index.php             # Main application (all pages)
│   ├── functions.php         # Helper functions & API
│   ├── config.php            # Database configuration
│   ├── setup.php             # One-time admin setup
│   ├── style.css             # All styles (responsive design)
│   └── README.md             # Setup instructions
├── astroforum-schema.drawio  # Database schema diagram
├── SPEC.md                   # Feature specification
└── flake.nix                 # Nix development environment
```

## Configuration

Edit `web/config.php` to change database settings:

```php
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'astronomical_db';
const DB_USER = 'root';
const DB_PASS = '';  // Add password if set
```

## Security Notes

- All database queries use prepared statements (SQL injection prevention)
- User inputs are escaped in HTML output
- CSRF tokens on all forms
- Passwords hashed with `PASSWORD_DEFAULT` (bcrypt)
- Session validation on protected pages
- Admin-only operations require role verification

## Troubleshooting

### MySQL Connection Error
- Ensure MySQL is running in XAMPP
- Check `DB_HOST`, `DB_USER`, `DB_PASS` in `config.php`
- On XAMPP, default is host `127.0.0.1`, user `root`, no password

### Registration Stuck on "Pending"
- Log in as admin
- Go to Admin Desk
- Approve or reject pending registrations

### Database Doesn't Exist
- Run: `mysql -u root < db\schema.sql`
- Recreate forum tables: `mysql -u root < db\schema-forum.sql`
- Reseed data: `mysql -u root < db\seed.sql`

### Can't Create Admin on Setup Page
- Admin already exists (only one needed)
- Database may not be initialized
- Refresh page: `setup.php`

## API Functions (functions.php)

Key helper functions available:

```php
// Authentication
current_user(): ?array          // Get logged-in user
require_user(): array           // Require login
require_admin(): array          // Require admin role
require_expert(): array         // Require expert+ role

// Forum
get_categories(): array         // All categories
get_threads_by_category(int): array
get_posts_for_thread(int): array
create_thread(...): int         // Returns thread ID
create_post(...): int           // Returns post ID

// Proposals
get_pending_proposals(): array
create_proposal(...): int       // Returns proposal ID
approve_proposal(int, int): void
reject_proposal(int, int, string): void

// Users
get_user_stats(int): array      // Posts, proposals, approvals
get_user_history(int): array    // Activity history
```

## License

Part of a crowdsourced astronomical catalogue project.

## Next Steps

1. Register an account
2. Wait for admin approval (log in as admin to approve)
3. Browse the forum
4. Create a discussion or proposal
5. Experts can review proposals from the Approvals page

