# AstroForum - Test Checklist

## Installation Verification

- [ ] XAMPP installed with Apache and MySQL
- [ ] Project copied to `c:\xampp\htdocs\astronomical-db\` (Windows) or equivalent
- [ ] Database schemas loaded (`schema.sql` and `schema-forum.sql`)
- [ ] Seed data loaded (`seed.sql`)
- [ ] Admin account created via `setup.php`

## Core Features Testing

### Authentication System
- [ ] User registration page loads
- [ ] Username validation (3-64 chars, alphanumeric + underscore)
- [ ] Password validation (8+ chars)
- [ ] Registration creates pending user
- [ ] Admin can approve registrations
- [ ] Admin can reject registrations
- [ ] User login works with approved account
- [ ] User logout works
- [ ] Session persists across page refreshes
- [ ] Unauthorized users redirected to login

### Dashboard & User Profiles
- [ ] User dashboard shows profile info
- [ ] Dashboard displays expertise level
- [ ] Dashboard shows post/proposal/approval counts
- [ ] User profile pages accessible via username link
- [ ] Profile shows activity history
- [ ] Recent activity displayed in chronological order

### Forum System
- [ ] Forum page shows all categories
- [ ] Category pages show thread count
- [ ] Categories have correct object types
- [ ] Users can create new threads (authenticated only)
- [ ] Thread types work: discussion, identification, proposal
- [ ] Threads display title, author, post count
- [ ] Thread timestamps display correctly
- [ ] Thread view shows all posts in order
- [ ] Users can reply to open threads
- [ ] Posts show author info and timestamp
- [ ] Original poster (OP) badge displays correctly

### Proposal System
- [ ] Users can create proposals from threads
- [ ] Proposals appear in approvals dashboard
- [ ] Experts can approve proposals
- [ ] Experts can reject proposals with reason
- [ ] Approved proposals change status
- [ ] Rejected proposals show reason
- [ ] Pending proposals display correctly

### Admin Features
- [ ] Admin dashboard accessible to admins only
- [ ] Registration queue displays pending users
- [ ] Admin can see username and signup time
- [ ] Approve button works
- [ ] Reject button works
- [ ] Queue updates after action
- [ ] Non-admins cannot access admin pages

### Expertise & Permissions
- [ ] Normal users cannot access approvals page
- [ ] Experts can access approvals page
- [ ] Verified users can approve proposals
- [ ] Admin can perform expert actions
- [ ] User expertise level displays on profile
- [ ] Expertise badge displays in posts

### Data Integrity
- [ ] Database connections work correctly
- [ ] All foreign keys properly set
- [ ] Cascade deletes work on threads/posts
- [ ] Timestamps auto-update
- [ ] Prepared statements prevent SQL injection
- [ ] CSRF tokens prevent form attacks

### Error Handling
- [ ] Invalid page requests show appropriate error
- [ ] Database errors handled gracefully
- [ ] Form validation shows error messages
- [ ] Missing required fields show errors
- [ ] Unauthorized access returns 403

### UI/UX
- [ ] Navigation works on all pages
- [ ] Flash messages display clearly
- [ ] Form styling is consistent
- [ ] Responsive layout works on mobile
- [ ] Links navigate correctly
- [ ] Page titles update appropriately

## Performance Checks

- [ ] Page load time < 1 second (local)
- [ ] Database queries use prepared statements
- [ ] No N+1 query problems
- [ ] Session handling efficient
- [ ] Memory usage stable over time

## Security Checks

- [ ] Passwords are hashed (bcrypt)
- [ ] SQL injection prevented (prepared statements)
- [ ] CSRF protection on all forms
- [ ] XSS prevention (HTML escaping)
- [ ] Session validation implemented
- [ ] Admin-only pages check role
- [ ] Expert-only pages check expertise
- [ ] No sensitive data in logs

## Content Validation

- [ ] Sample objects exist in database
- [ ] Categories visible in forum
- [ ] Sample threads can be created
- [ ] Proposal workflow complete
- [ ] User activity tracked correctly

## Deployment Verification

- [ ] README.md provides clear setup steps
- [ ] DEPLOYMENT.md has complete walkthrough
- [ ] Configuration in `config.php` accurate
- [ ] All files in correct locations
- [ ] Database schema complete
- [ ] Seed data loads without errors

## Edge Cases

- [ ] Creating thread with very long title
- [ ] Creating post with special characters
- [ ] Fast consecutive requests handled
- [ ] User registration with duplicate username
- [ ] Login with wrong password
- [ ] Accessing thread that doesn't exist
- [ ] Accessing user profile that doesn't exist
- [ ] Session timeout behavior

## Browser Compatibility (Manual Testing)

- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge
- [ ] Mobile browsers

## Known Limitations (Document)

- [ ] No image upload support yet
- [ ] No email notifications
- [ ] No full-text search
- [ ] No markdown in posts
- [ ] No dispute resolution UI (database support only)
- [ ] Single-file index.php (monolithic)
- [ ] No API layer

## Post-Launch Checklist

- [ ] Documentation complete
- [ ] All features tested
- [ ] Error messages user-friendly
- [ ] Database backups automated
- [ ] Monitoring set up
- [ ] Support process defined

---

**Last Updated**: 2026-08-27
**Version**: 1.0-complete
