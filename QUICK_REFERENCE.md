# AstroForum - Quick Reference Guide

## URLs Reference

| Page | URL |
|------|-----|
| **Home** | `http://localhost/astronomical-db/web/` |
| **Register** | `http://localhost/astronomical-db/web/index.php?page=register` |
| **Login** | `http://localhost/astronomical-db/web/index.php?page=login` |
| **Dashboard** | `http://localhost/astronomical-db/web/index.php?page=dashboard` |
| **Forum** | `http://localhost/astronomical-db/web/index.php?page=forum` |
| **Category** | `http://localhost/astronomical-db/web/index.php?page=forum&cat={slug}` |
| **Thread** | `http://localhost/astronomical-db/web/index.php?page=thread&thread={id}` |
| **Profile** | `http://localhost/astronomical-db/web/index.php?page=profile&user={id}` |
| **Admin Desk** | `http://localhost/astronomical-db/web/index.php?page=admin` |
| **Approvals** | `http://localhost/astronomical-db/web/index.php?page=approvals` |
| **Setup** | `http://localhost/astronomical-db/web/setup.php` |

## Default Test Data

### Objects
- **Sirius** (Star) - Canis Major - 8 ly
- **Andromeda** (Galaxy) - Andromeda - 2,537,000 ly
- **Orion** (Nebula) - Orion - 1,344 ly

### Categories
- General Discussion
- Stars
- Galaxies
- Nebulae
- Planets & Moons
- Unidentified Objects

## User Roles & Permissions

### Member
- [x] Register account
- [x] Create posts
- [x] Create proposals
- [x] View profiles
- [x] Browse forum
- [ ] Approve proposals
- [ ] Access admin tools

### Expert
- [x] All Member permissions
- [x] Approve proposals
- [x] Reject proposals
- [x] View approvals queue
- [ ] Admin functions

### Admin
- [x] All Expert permissions
- [x] Approve/reject registrations
- [x] Verify users manually
- [x] Access admin dashboard
- [x] View pending queue

## Common Workflows

### Workflow 1: Register & Post
```
1. Go to Register page
2. Fill username & password
3. Submit registration
4. Admin approves you
5. Login with credentials
6. Go to Forum
7. Select category
8. Create thread with message
9. Others reply
```

### Workflow 2: Propose New Object
```
1. Login as member
2. Go to Forum > relevant category
3. Create thread (type: "Propose new object")
4. Add object details
5. Expert views in Approvals
6. Expert approves
7. Object added to catalogue
```

### Workflow 3: Identify Unknown Object
```
1. Go to Forum > Unidentified Objects
2. Create thread (type: "Help identifying object")
3. Describe what you're looking for
4. Community discusses
5. When identified, OP (you) confirms
```

### Workflow 4: Admin Approval
```
1. Login as admin
2. Click "Admin desk"
3. See pending registrations
4. Click Approve for each user
5. User gets notification (via login feedback)
```

### Workflow 5: Expert Approval
```
1. Login as expert
2. Click "Approvals"
3. See pending proposals
4. Review each proposal
5. Click Approve or add reason & Reject
```

## Form Validation Rules

| Field | Rules |
|-------|-------|
| **Username** | 3-64 chars, letters/numbers/underscore |
| **Password** | 8+ characters |
| **Thread Title** | 3+ characters |
| **Post Body** | 3+ characters |
| **Thread Message** | 5+ characters |
| **Reject Reason** | 3+ characters |

## Database Commands

The application uses two databases: `astronomical_catalogue` and `astronomical_forum`.

### Backup Database
```bash
mysqldump -u root astronomical_catalogue > catalogue-backup.sql
mysqldump -u root astronomical_forum > forum-backup.sql
```

### Restore Database
```bash
mysql -u root astronomical_catalogue < catalogue-backup.sql
mysql -u root astronomical_forum < forum-backup.sql
```

### View All Users
```bash
mysql -u root -e "SELECT id, username, role, expertise, registration_status FROM astronomical_forum.users;"
```

### View All Proposals
```bash
mysql -u root -e "SELECT id, type, status, author_id FROM astronomical_forum.proposals;"
```

### View All Threads
```bash
mysql -u root -e "SELECT id, title, type, status FROM astronomical_forum.threads;"
```

### Reset Admin (if needed)
```bash
mysql -u root -e "DELETE FROM astronomical_forum.users WHERE role='admin';"
# Then recreate via setup.php
```

### View Recent Posts
```bash
mysql -u root -e "SELECT * FROM astronomical_forum.posts ORDER BY created_at DESC LIMIT 10;"
```

## Troubleshooting

### "Access Denied" Error
- Ensure MySQL is running
- Check credentials in `config.php`
- Verify password is correct

### White Screen
- Check PHP error log
- Ensure database exists
- Verify table creation

### Can't Register Users
- Admin account must be created first via `setup.php`
- Then admin must approve registrations

### Forgot Admin Password
- Delete admin: `DELETE FROM users WHERE role='admin';`
- Recreate via `setup.php`
- Data won't be affected

### Missing CSS/Styling
- Ensure XAMPP is serving static files
- Check browser console for 404 errors
- Verify `style.css` in web folder

### Duplicate Username Error
- Username must be unique
- Choose different username

### Proposal Not Appearing
- Ensure you're logged in
- Proposal must be created in a thread
- Check "Approvals" page as expert

## File Modifications Guide

### Change Database Credentials
Edit `web/config.php`:
```php
const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = 'your_password';
```

### Add New Object Types
Edit `db/seed.sql` in categories section:
```sql
INSERT INTO categories (name, slug, object_type, description)
VALUES ('Asteroids', 'asteroids', 'asteroid', 'Description...');
```

### Change Site Title
Edit `web/index.php`, search for "AstroForum", replace all

### Enable Debug Mode
Add to top of `web/index.php`:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Change Message of the Day (MOTD)
Edit hero section in `web/index.php`, modify text

## Performance Tips

1. **Database Optimization**:
   - Indexes created on: username, category_id, author_id, status
   - Use prepared statements (already done)

2. **Scaling Up**:
   - Add Redis for session caching
   - Add Memcached for query results
   - Split PHP into controllers (MVC)
   - Add API layer for mobile apps

3. **Monitoring**:
   - Watch error logs daily
   - Monitor disk usage for database
   - Check slow query log

## API Reference (functions.php)

### Authentication
```php
current_user(): ?array
require_user(): array
require_admin(): array
require_expert(): array
```

### Forum
```php
get_categories(): array
get_threads_by_category(int): array
get_posts_for_thread(int): array
create_thread(...): int
create_post(...): int
```

### Proposals
```php
get_pending_proposals(int): array
approve_proposal(int, int): void
reject_proposal(int, int, string): void
```

### Users
```php
get_user_by_id(int): ?array
get_user_stats(int): array
get_user_history(int): array
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Ctrl+Shift+Delete` | Clear browser cache |
| `F12` | Open developer console |
| `Ctrl+U` | View page source |

## Email Template (if email added later)

```
Subject: [AstroForum] Your proposal was approved!

Hello {username},

Your proposal to {action} has been approved by {expert_name}.

View proposal: {thread_url}

Best regards,
AstroForum Admin
```

## Disaster Recovery Checklist

If something breaks:

1. [ ] Check database is running
2. [ ] Review error logs
3. [ ] Restart XAMPP services
4. [ ] Restore from latest backup
5. [ ] Verify database integrity
6. [ ] Clear browser cache
7. [ ] Test with fresh browser session

## Support Contacts

- **Documentation**: README.md, DEPLOYMENT.md, SPEC.md
- **Errors**: Check PHP error log
- **Database Issues**: Use MySQL tools
- **Feature Requests**: Update SPEC.md

---

**Last Updated**: 2026-08-27  
**Version**: 1.0  
**Questions?** See README.md or DEPLOYMENT.md
