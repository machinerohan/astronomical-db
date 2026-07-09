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

Every user has a **role** (site management) and an **expertise** badge
(knowledge level). The two axes are independent.

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

An admin carries an expertise badge like any other user; it is visible on
their profile.

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

All user-generated content is organized as discussions of three types:

- **general** — an open conversation, optionally linked to an object
- **identification** — a request to identify an unknown object
- **proposal** — a suggested change to the catalogue

Every discussion is either `open` or `closed`. A proposal discussion also
carries an approval state — `pending`, `approved`, or `rejected` — and a
resolved identification links directly to the object it identified. Each
discussion type has exactly the columns it needs, so a proposal's approval
state and an identification's resolved object never interfere with one another.

### 2.1 General discussions

Any user can start a general discussion thread, optionally linked to a
catalogue object. Anyone can comment. No approval required.

### 2.2 Identification discussions

A user posts an image or description of an unknown object and asks for
identification. Other users comment with their best guess, optionally
referencing a known catalogue object via `@obj:Sirius` or describing a
potentially new object.

**Resolution:**
- The original poster (or an admin) accepts a comment as the correct answer.
  When the answer matches an existing catalogue object, the discussion links to
  that object through `identified_object_id`.
- When the answer points to a brand-new object, a **proposal** for that object
  is created with its `parent_discussion_id` set to the identification
  discussion. The chain identification → proposal → catalogue entry stays
  visible on the object's page.
- When no answer is found, the OP (or an admin) closes the discussion.

Resolved identifications appear on the identified object's page.

### 2.3 Proposal discussions

A proposal is a discussion that carries structured change data in
`proposal_data` (JSON) and moves through an approval workflow. Three kinds of
proposal exist:

- **New object**: the user fills out the full object form (name, type,
  coordinates, magnitude, etc.).
- **Edit**: the user proposes a change to fields on an existing object.
- **Mark as bad**: the user flags a previously approved contribution as
  incorrect, with a reason.

A proposal starts in `pending` state. Expert, verified, and admin users can
approve or reject any pending proposal, including their own.

Any user can submit a mark-as-bad proposal, but only verified and admin users
can approve one. When approved, the author's net score decreases and the object
history records the correction.

While a proposal is `pending`, its author may update `proposal_data` in
response to the thread's discussion. The data in effect when the proposal is
approved is the data applied to the catalogue.

When a proposal originates from an identification (because the ID discovered a
new object), its `parent_discussion_id` points back to the identification
discussion, so the object page shows the full chain.

### 2.4 Comments

Comments form a single flat list under each discussion. Each comment has an
anchor link for direct referencing. On identification discussions, one comment
carries `is_accepted` to mark the accepted answer.

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
discoverer, discovery year, and notes. Coordinates are stored as J2000
sexagesimal strings (e.g. `06:45:08.9`, `-16:42:58`), matching the
astronomical convention used by sources such as SIMBAD and OpenNGC.

### 3.2 Object history

Each object's page shows how its data was assembled:
- The proposal that created it.
- Every approved edit that changed it.
- Every resolved identification linked to it (via `identified_object_id`).
- Any contributions later marked as incorrect and why.

### 3.3 Direct editing

Expert and above users can edit the catalogue immediately. A proposal
discussion is created in `approved` state to record the change. This is the same
approval mechanism — the user has permission to approve proposals, so they
approve their own right away.

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

Community contributions are recorded as discussions: proposals with their
reviews, identification resolutions, and accepted answers all live in the
discussion and comment records.

Three administrative operations are recorded separately in `admin_actions`:
- Creating a temporary user account
- Manually demoting a user
- Granting verified status

Each entry records who performed it, the target, relevant metadata, and a
timestamp.

---

## 6. Database Schema

Six tables: `users`, `objects`, `discussions`, `comments`, `admin_actions`,
and the catalogue reference `objects` (defined in `db/schema.sql`). All forum
tables live in `htdocs/schema-forum.sql`.

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
updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### 6.3 `discussions`

One table holds all three discussion types. A proposal carries its change data
and approval state, an identification carries the resolved object link, and a
proposal spawned from an identification carries the parent link back to it.

```
id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
object_id             INT UNSIGNED NULL
type                  ENUM('general','identification','proposal') NOT NULL DEFAULT 'general'
title                 VARCHAR(255) NOT NULL
body                  TEXT NOT NULL
author_id             INT UNSIGNED NOT NULL
status                ENUM('open','closed') NOT NULL DEFAULT 'open'

-- Proposal columns
proposal_type         ENUM('new_object','edit_field','mark_bad') NULL
proposal_data         JSON NULL
proposal_status       ENUM('pending','approved','rejected') NULL
reviewer_id           INT UNSIGNED NULL
reviewed_at           DATETIME NULL

-- Identification column
identified_object_id  INT UNSIGNED NULL

-- Link: a proposal spawned from an identification discussion
parent_discussion_id  INT UNSIGNED NULL

-- Closing
closed_by             INT UNSIGNED NULL
closed_reason         VARCHAR(255) NULL

created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.4 `comments`

```
id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
discussion_id     INT UNSIGNED NOT NULL
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
