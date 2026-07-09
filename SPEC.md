# Astronomical Objects Community Forum — Specification

## Overview

A crowd-sourced forum where users discuss astronomical objects, identify
unknown ones, and propose new catalogue entries or changes to existing ones.
Trusted contributors earn expert status automatically. The catalogue grows
through community effort with expert oversight.

---

## 1. Users

### 1.1 Account creation

Users register with a username and password (bcrypt-hashed). Admins can create
temporary accounts — a random password is shown to the admin once to share out
of band. On first login with a temporary password, the user must choose their
own password.

### 1.2 Roles

Two independent axes: **role** (site management) and **expertise** (knowledge
badge).

**Role:**
- `admin` — manage users, grant verified status, write verification notes,
  demote experts, close discussions, resolve identifications.
- `member` — participate in discussions, submit identifications, propose
  objects, suggest changes.

**Expertise:**
- `normal` — base permissions only.
- `expert` — can approve or reject proposals. Earned automatically through
  contributions.
- `verified` — same approval power as expert, distinct badge. Granted manually
  by admin after private verification (e.g., confirming professional
  credentials).

An admin may be normal, expert, or verified; the badge is cosmetic for admins
but visible on their profile.

### 1.3 Verification notes

When granting verified status, an admin may attach a private note (e.g.,
"confirmed astronomer — checked LinkedIn"). These notes are visible to
admins only.

### 1.4 Profile page

Each user profile shows:
- Username, role, and expertise badges
- Contribution history (proposals submitted, IDs resolved, approved
  contributions, contributions marked bad)
- Date joined

---

## 2. Discussions

All user-generated content is organized as discussions — forum threads,
identification requests, and catalogue proposals.

Each discussion has a type, title, body, author, and optional link to a
catalogue object.

### 2.1 General discussions

Any user can start a general discussion thread, optionally linked to a
catalogue object. Anyone can comment. No approval required.

### 2.2 Identification discussions

A user posts an image or description of an unknown object and asks for
identification. Other users comment with their best guess, optionally
referencing a known catalogue object via `@obj:Sirius` or describing a
potentially new object.

**Resolution:**
- The original poster can accept any comment as the correct answer. The
  discussion is marked identified and linked to the catalogue object.
- If no answer is found, the OP can close the discussion as unknown.
- Admins can accept a comment or close the discussion on any identification.

Resolved identifications appear on the identified object's page.

### 2.3 Proposal discussions

Proposals add new objects to the catalogue or suggest changes to existing
ones. Both follow the same approval process.

- **New object**: the user fills out the full object form (name, type,
  coordinates, magnitude, etc.).
- **Edit**: the user specifies a single field change on an existing object
  (e.g., update distance from 1500 to 1600 ly).

Proposals are created pending review. Expert, verified, and admin users can
approve or reject any pending proposal, including their own.

Marking a previously approved contribution as incorrect is also a proposal.
Any user can submit one, but only verified and admin users can approve it.
When approved, the author's net score decreases and the object history shows
the correction.

When a proposal is linked to an identification (because the ID discovered a
new object), the object page shows the full chain: identification → proposal
→ catalogue entry.

### 2.4 Comments

Discussions support threaded comments. Each comment has an anchor link for
direct referencing. On identification discussions, a comment can be marked as
the accepted answer.

### 2.5 Reference syntax

Discussion bodies and comments are rendered with clickable references:

| Syntax | Links to |
|---|---|
| `@username` | User profile |
| `@obj:Sirius` | Catalogue object (by name or catalog ID) |
| `@discussion:42` | Discussion thread |
| `@comment:123` | Specific comment |

All output is HTML-escaped for safety.

---

## 3. Catalogue

### 3.1 Objects

The catalogue stores astronomical objects with fields for name, catalog ID,
object type, right ascension, declination, magnitude, constellation, distance,
discoverer, discovery year, and notes.

### 3.2 Object history

Each object's page shows how its data was assembled:
- The proposal that created it.
- Every approved edit that changed it.
- Every resolved identification linked to it.
- Any contributions later marked as incorrect and why.

### 3.3 Direct editing

Expert and above users can edit the catalogue immediately without waiting for
approval. A proposal discussion is created in approved state to record the
change. This is the same approval mechanism — the user has permission to
approve proposals, so they approve their own right away.

---

## 4. Moderation and Quality

### 4.1 Permissions

| Action | normal | expert | verified | admin |
|---|---|---|---|---|
| Comment, start discussions | ✓ | ✓ | ✓ | ✓ |
| Propose object or change | ✓ | ✓ | ✓ | ✓ |
| Submit identification | ✓ | ✓ | ✓ | ✓ |
| Approve or reject proposals | — | ✓ | ✓ | ✓ |
| Propose marking contribution as bad | ✓ | ✓ | ✓ | ✓ |
| Approve mark-bad proposals | — | — | ✓ | ✓ |
| Accept answer on own ID | ✓ | ✓ | ✓ | ✓ |
| Accept answer on any ID | — | — | — | ✓ |
| Close any discussion | — | — | — | ✓ |
| Manage users, demote, verify | — | — | — | ✓ |
| Read/write verification notes | — | — | — | ✓ |

### 4.2 Auto-promotion

A user's net score is the number of approved proposals minus the number of
their contributions marked as bad. When it reaches 5, the user is promoted to
expert. Promotion is recalculated whenever a proposal is approved, rejected,
or marked bad.

### 4.3 Auto-demotion

If an expert's net score drops below 5 (due to a contribution marked bad),
they are demoted to normal. They can regain expert status by contributing
more.

Admins can also manually demote an expert. A manually demoted user cannot be
re-auto-promoted until an admin clears the restriction.

### 4.4 Protected contributions

Only incorrect data is marked as bad. Data superseded by better measurements
remains valid — a later, more accurate measurement does not retroactively
invalidate the original contribution.

---

## 5. Audit Trail

All community contributions are recorded as discussions. Proposals,
approvals, rejections, identification resolutions, and accepted answers are
all captured by the discussion and comment records.

Three operations involve no discussion and are recorded separately:
- Creating a temporary user account
- Manually demoting a user
- Granting verified status

Each such action records who performed it, what target, any relevant metadata,
and a timestamp.

---

## 6. Database Schema

### 6.1 `users`

```
id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
username            VARCHAR(64) NOT NULL UNIQUE
password            VARCHAR(255) NOT NULL
role                ENUM('admin','member') DEFAULT 'member'
expertise           ENUM('normal','expert','verified') DEFAULT 'normal'
temp_password       VARCHAR(255) NULL
admin_demoted_at    TIMESTAMP NULL
verified_by_id      INT UNSIGNED NULL
verified_at         DATETIME NULL
verification_note   TEXT NULL
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.2 `objects`

```
id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
name                VARCHAR(255) NOT NULL
catalog_id          VARCHAR(64) NULL UNIQUE
object_type         VARCHAR(64) NOT NULL
right_ascension     VARCHAR(16) NULL
declination         VARCHAR(16) NULL
apparent_mag        DECIMAL(6,3) NULL
constellation       VARCHAR(16) NULL
distance_ly         DECIMAL(12,3) NULL
discovered_by       VARCHAR(128) NULL
discovery_year      SMALLINT UNSIGNED NULL
notes               TEXT NULL
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.3 `discussions`

```
id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
object_id             INT UNSIGNED NULL
type                  ENUM('general','identification','proposal') DEFAULT 'general'
title                 VARCHAR(255) NOT NULL
body                  TEXT NOT NULL
author_id             INT UNSIGNED NOT NULL
status                ENUM('open','identified','unknown','pending','approved','rejected','closed') NULL
proposal_data         JSON NULL
parent_discussion_id  INT UNSIGNED NULL
closed_by             INT UNSIGNED NULL
closed_reason         TEXT NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.4 `comments`

```
id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
discussion_id     INT UNSIGNED NOT NULL
parent_id         INT UNSIGNED NULL
body              TEXT NOT NULL
author_id         INT UNSIGNED NOT NULL
is_accepted       BOOLEAN DEFAULT FALSE
created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.5 `admin_actions`

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
admin_id        INT UNSIGNED NOT NULL
action          ENUM('create_user','demote_user','verify_user')
target_type     ENUM('user') NOT NULL
target_id       INT UNSIGNED NOT NULL
metadata        JSON NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

## 7. PHP Application Structure

```
htdocs/
├── index.php
├── login.php
├── logout.php
├── register.php
├── change-password.php
├── profile.php
├── objects.php
├── discussion.php
├── new-discussion.php
├── propose.php
├── includes/
│   ├── db.php
│   ├── auth.php
│   └── functions.php
├── admin/
│   ├── index.php
│   ├── create-user.php
│   ├── users.php
│   ├── proposals.php
│   └── contributions.php
└── schema-forum.sql
```

---

## 8. Local Development

### Windows — XAMPP

```
1. Install XAMPP, start Apache + MySQL
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
