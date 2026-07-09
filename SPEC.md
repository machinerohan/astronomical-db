# Astronomical Objects Community Forum — Specification

## Overview

A crowd-sourced MySQL/PHP forum where users discuss astronomical objects,
submit identifications of unknown objects, propose new catalogue entries, and
suggest edits to existing ones. Trusted contributors earn expert status
automatically; admins can verify professionals. The catalogue grows through
community effort with expert oversight.

Runs on XAMPP (Windows, primary platform) or Nix (Linux development).

---

## 1. Users

### 1.1 Account creation

Users register with a username and password. Passwords are bcrypt-hashed.
Admins can also create temporary accounts — a random password is generated and
shown to the admin once, who shares it out of band. On first login with a
temporary password, the user is forced to set their own password.

### 1.2 Roles

Two orthogonal axes: **role** (site management) and **expertise** (knowledge badge).

**Role:**
- `admin` — full site control. Manage users, grant verified status, write
  verification notes, demote experts, close any discussion, resolve any
  identification, mark contributions as bad.
- `member` — participate in discussions, submit identifications, propose
  objects, suggest edits.

**Expertise:**
- `normal` — base permissions only.
- `expert` — can approve/reject proposals and edits submitted by others.
  Earned automatically through contributions.
- `verified` — same approval power as expert, distinct badge. Granted manually
  by admin after private verification (e.g., confirming professional
  credentials). Supersedes expert — a verified user has no need of expert.

Admin and member are independent of expertise. An admin can be normal, expert,
or verified; the expertise badge is cosmetic for admins but visible on their
profile.

### 1.3 Verification notes

Admins can write private notes attached to a user when granting verified
status (e.g., "confirmed astronomer — checked LinkedIn"). These notes are
never exposed to users. Only admins can read and write them.

### 1.4 Profile

Each user has a profile page showing:
- Username and role/expertise badges
- Their contribution log (proposals submitted, edits suggested, IDs resolved,
  contributions approved, contributions marked bad)
- Date joined

---

## 2. Content — Everything is Discussions

A single `discussions` table covers all user-generated content: general forum
threads, identification requests, object proposals, and catalog edits.

Each discussion has:
- `type` — `general`, `identification`, `proposal`, or `edit`
- `title` and `body`
- `author_id` — who started it
- `object_id` — optional link to a catalogue object
- `status` — varies by type
- Resolution tracking (for identifications)
- Approval tracking (for proposals and edits)

### 2.1 General discussions

Any user can start a general discussion thread, optionally linked to an
catalogue object (started from the object detail page) or standalone. Anyone
can comment. No approval needed.

### 2.2 Identification discussions

A user posts an image or description of an unknown object, asking "what is
this?" Other users comment with their identification. Each identification
comment can reference a known object via `@obj:Sirius` or describe a
potentially new object.

**Resolution:**
- The original poster (OP) can accept any comment as the correct answer by
  clicking "Accept" on that comment. This marks the discussion as
  `identified` and links it to the identified object.
- If no satisfactory answer is found, the OP can close the discussion as
  `unknown`.
- Admins can accept any comment on any identification (useful when OP is
  absent). Admins can also close any discussion as a moderation action.

When an identification is resolved, the identified object page shows past
identification requests and their outcome.

### 2.3 Proposal discussions

When a user wants to add a new object to the catalogue, they fill out a form
with the object's data (name, type, coordinates, magnitude, etc.). This
creates a `type=proposal` discussion with the form data stored in
`proposal_data` JSON.

**Approval flow:**
- If the submitter is `normal`, the proposal is created with
  `status = pending`. An expert, verified, or admin must review and
  approve or reject it.
- If the submitter is `expert`, `verified`, or `admin`, the proposal is
  **auto-approved** immediately — the row is inserted into the `objects`
  table and the discussion is set to `status = approved`.

When a proposal is linked to an identification discussion (`parent_discussion_id`),
the object detail page shows the full chain: ID request → proposal → catalogue
entry.

### 2.4 Edit discussions

Any user can propose a change to a single field on an existing catalogue
object. This creates a `type=edit` discussion storing the field name, old
value, and new value.

**Approval flow:**
- `normal` users: pending, waits for expert approval.
- `expert` and above: auto-approved immediately. The `objects` row is updated
  and the discussion set to `status = approved`.

### 2.5 Comments

All discussions support threaded comments via `parent_id`. Each comment has an
anchor link (`#c123`) for direct referencing.

### 2.6 Reference syntax

User-generated text (discussion bodies and comments) is rendered through a
parser that converts references into clickable links:

| Syntax | Links to |
|---|---|
| `@username` | User profile page |
| `@obj:Sirius` | Catalogue object page (by name or catalog ID) |
| `@discussion:42` | Discussion thread |
| `@comment:123` | Specific comment (with anchor) |

The parser runs `htmlspecialchars` for safety before inserting links.

---

## 3. Catalogue

### 3.1 The `objects` table

The core catalogue stores astronomical objects with fields for name, catalog
ID, object type, right ascension, declination, apparent magnitude,
constellation, distance in light years, discoverer, discovery year, and notes.

### 3.2 Object history timeline

Each object's detail page shows a full history of how its data was assembled:
- Initial creation (which proposal discussion, who proposed it, who approved)
- Every approved edit (who suggested, who approved)
- Every accepted identification request resolved to this object
- Any contributions later marked as bad and why

This makes the provenance of every data point transparent.

### 3.3 Direct editing by experts and above

Users with `expert` expertise or higher can edit the catalogue directly —
changes take effect immediately without going through the proposal queue. A
discussion of type `edit` is still created for history (auto-approved), so the
change appears in the object's timeline.

---

## 4. Moderation and Quality

### 4.1 Permission matrix

| Action | normal | expert | verified | admin |
|---|---|---|---|---|
| Comment, start discussions | ✓ | ✓ | ✓ | ✓ |
| Propose new object | ✓ | ✓ | ✓ | ✓ |
| Suggest edit | ✓ | ✓ | ✓ | ✓ |
| Submit identification | ✓ | ✓ | ✓ | ✓ |
| Approve/reject others' proposals | — | ✓ | ✓ | ✓ |
| Approve/reject others' edits | — | ✓ | ✓ | ✓ |
| Edit catalogue directly | — | ✓ | ✓ | ✓ |
| Accept answer on own ID | ✓ | ✓ | ✓ | ✓ |
| Accept answer on any ID | — | — | — | ✓ |
| Close any discussion | — | — | — | ✓ |
| Mark contributions as bad | — | — | ✓ | ✓ |
| Manage users, demote, verify | — | — | — | ✓ |
| Read/write verification notes | — | — | — | ✓ |

### 4.2 Marking contributions as bad

Verified users and admins can flag a previously approved proposal or edit as
incorrect (`mark_bad` in the contribution log). This does not delete the
contribution — it adds a note to the history timeline explaining why it was
wrong (e.g., "incorrect distance measurement, superseded by Gaia DR4").

When a contribution is marked bad, the author's net approved count decreases.
This may trigger auto-demotion (see below).

Experts cannot mark contributions as bad. They can only approve or reject.

### 4.3 Auto-promotion to expert

Expertise is computed dynamically from the contribution log, not stored as a
static counter.

```
Net score = (approved proposals + approved edits) - (contributions marked bad)
```

When a user's net score reaches 5, they are automatically promoted to
`expert`. Promotion is recalculated on every relevant event (approval,
rejection, mark-bad).

### 4.4 Auto-demotion from expert

If a demoted (not marked bad) contribution drops an expert's net score below
5, the system automatically demotes them back to `normal`. They can regain
expert status by contributing more.

Admins can also manually demote an expert (e.g., for bad-faith
contributions), which sets a flag blocking re-auto-promotion until the admin
clears it.

### 4.5 Protected contributions

Marking a contribution as bad is for **incorrect data**, not for obsolete data
that has been superseded by better measurements or changes in scientific
consensus. If a later, more accurate measurement replaces an old one, the old
contribution remains valid for its time and is not marked bad. This ensures
users are not penalized for contributing honest data that later science
surpasses.

---

## 5. Contribution Log

The `contribution_log` table records every meaningful action. It is the
source of truth for user expertise and object history.

Recorded actions:
- `propose_object` — a proposal discussion was created
- `propose_edit` — an edit discussion was created
- `approve_proposal` — a proposal was approved (manual or auto)
- `approve_edit` — an edit was approved (manual or auto)
- `reject_proposal` / `reject_edit` — a proposal or edit was rejected
- `direct_edit` — an expert or above edited the catalogue directly
- `mark_bad` — a contribution was flagged as incorrect (metadata includes
  reason and original approver)
- `resolve_id` — an identification was resolved (metadata includes which
  comment was accepted)
- `close_discussion` — a discussion was closed by admin
- `create_user` — admin created a temporary account
- `demote_user` / `verify_user` — admin changed a user's expertise

Each log entry stores `user_id`, `action`, `target_type`, `target_id`,
`metadata` (JSON), and a timestamp.

---

## 6. Database Schema

### 6.1 `users`

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
username        VARCHAR(64) NOT NULL UNIQUE
password        VARCHAR(255) NOT NULL          -- bcrypt hash
role            ENUM('admin','member') DEFAULT 'member'
expertise       ENUM('normal','expert','verified') DEFAULT 'normal'
temp_password   VARCHAR(255) NULL              -- bcrypt of temp password, cleared after first login
admin_demoted_at TIMESTAMP NULL                -- set when admin demotes, blocks auto re-promotion
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.2 `verification_notes`

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id         INT UNSIGNED NOT NULL UNIQUE
admin_id        INT UNSIGNED NOT NULL
note            TEXT NOT NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
```

### 6.3 `objects` (catalogue)

```
id, name, catalog_id, object_type, right_ascension, declination,
apparent_mag, constellation, distance_ly, discovered_by, discovery_year,
notes, created_at

Primary key on id
Unique key on catalog_id
Indexes on object_type, constellation
```

### 6.4 `discussions`

```
id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
object_id           INT UNSIGNED NULL              -- linked catalogue object
type                ENUM('general','identification','proposal','edit') DEFAULT 'general'
title               VARCHAR(255) NOT NULL
body                TEXT NOT NULL
author_id           INT UNSIGNED NOT NULL

-- Identification resolution
status              ENUM('open','identified','unknown','approved','rejected','closed') NULL
resolved_comment_id INT UNSIGNED NULL
resolved_object_id  INT UNSIGNED NULL
resolved_by         INT UNSIGNED NULL

-- Edit fields (for type=edit)
edit_field          VARCHAR(64) NULL
edit_old_value      TEXT NULL
edit_new_value      TEXT NULL

-- Proposal data (for type=proposal, JSON of object fields)
proposal_data       JSON NULL

-- Parent ID link (proposal linked back to the ID that prompted it)
parent_discussion_id INT UNSIGNED NULL

-- Moderation
closed_by           INT UNSIGNED NULL
closed_reason       TEXT NULL

created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP

Foreign keys: object_id, author_id, resolved_comment_id, resolved_object_id,
              parent_discussion_id, closed_by
```

### 6.5 `comments`

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
discussion_id   INT UNSIGNED NOT NULL
parent_id       INT UNSIGNED NULL               -- nested reply support
body            TEXT NOT NULL
author_id       INT UNSIGNED NOT NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP

Foreign keys: discussion_id, author_id
```

### 6.6 `contribution_log`

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id         INT UNSIGNED NOT NULL
action          ENUM('propose_object','propose_edit','approve_proposal','approve_edit',
                     'direct_edit','reject_proposal','reject_edit','mark_bad',
                     'resolve_id','close_discussion','create_user','demote_user','verify_user')
target_type     ENUM('discussion','comment','user') NOT NULL
target_id       INT UNSIGNED NOT NULL
metadata        JSON NULL                        -- flexible: holds reason, original_approver, etc.
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP

Foreign key: user_id
```

---

## 7. PHP Application Structure

```
htdocs/
├── index.php                          -- Home: recent discussions, recent IDs
├── login.php                          -- Login form + POST handler
├── logout.php                         -- Destroy session, redirect
├── register.php                       -- Register form + POST handler
├── change-password.php                -- First login / voluntary password change
├── profile.php?u=username             -- User profile with contribution log
├── objects.php?id=N                   -- Object detail + history timeline + linked discussions
├── discussion.php?id=N                -- Thread + comments + reply form + resolve controls
├── new-discussion.php                 -- Start a discussion
├── propose-object.php                 -- Form for new catalogue object
├── propose-edit.php?object_id=N       -- Form for field edit suggestion
├── includes/
│   ├── db.php                         -- PDO connection (configurable)
│   ├── auth.php                       -- Session init, login check, permission checks
│   └── functions.php                  -- render_body(), auto_promote(), time_ago(), etc.
├── admin/
│   ├── index.php                      -- Dashboard summary
│   ├── create-user.php                -- Create temporary account
│   ├── users.php                      -- List/ manage users, set expertise, verification notes
│   ├── proposals.php                  -- Pending proposals (approve/reject)
│   ├── edits.php                      -- Pending edits (approve/reject)
│   └── contributions.php              -- Browse log, mark bad
└── schema-forum.sql                   -- All CREATE/ALTER statements
```

Each page follows a consistent pattern: POST handler at top, redirect on
success, HTML template at bottom. No framework — plain PHP with
`htmlspecialchars` for output and prepared statements for SQL.

---

## 8. Local Development

### Windows — XAMPP

```
1. Install XAMPP, start Apache + MySQL from the control panel
2. mysql -u root < db\schema.sql
3. mysql -u root < htdocs\schema-forum.sql
4. Place htdocs/ in C:\xampp\htdocs\astronomical\
5. Visit http://localhost/astronomical/
```

### Linux — Nix

```
nix develop
mysql -u root < db/schema.sql
mysql -u root < htdocs/schema-forum.sql
php -S localhost:8080 -t htdocs/
```

The Nix shell provides `mysql`, `mysqld`, and `mysqladmin` pre-configured
with a project-local data directory (`.mysql-data/`).
