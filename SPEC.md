# Astronomical Objects Community Forum — Specification

## Overview

A crowd-sourced forum where users discuss astronomical objects, identify
unknown ones, and propose new catalogue entries or changes to existing ones.
Trusted contributors earn expert status automatically. The catalogue grows
through community effort with expert oversight.

---

## 1. Users

### 1.1 Account creation

Only admins can create accounts. The admin sets an initial password — for
example a random one shown to the admin once, to share with the new user out
of band. Every user changes their own password through the normal
password-change feature at any time.

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
"confirmed astronomer — checked LinkedIn"). These notes are stored in a
separate `user_verifications` table and are visible to admins only.

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
discussion type uses its own set of columns, so a proposal's approval state
and an identification's resolved object are tracked independently.

### 2.1 General discussions

Any user can start a general discussion thread, optionally linked to a
catalogue object. Anyone can comment.

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
  is created with its `parent_discussion_id` set to this identification, and
  the system prompts the accepted comment's author (or the OP) to fill out the
  full object form as a `proposed_objects` row within the new proposal.
  `identified_object_id` stays `NULL` on this identification until that
  proposal is approved and the object exists, at which point the system sets
  `identified_object_id` to the newly created object.
- When no answer is found, the OP (or an admin) closes the discussion.

Resolved identifications appear on the identified object's page.

### 2.3 Proposal discussions

A proposal discussion collects suggested catalogue changes as proposed-data
rows linked to its comments. Three kinds of proposal exist:

- **New object**: a full object record (name, type, coordinates, magnitude,
  etc.).
- **Edit**: a change to one or more fields on an existing object.
- **Mark as bad**: a flag that a previously approved contribution is
  incorrect, with a reason.

Any user may post a comment and attach proposed data of any of these kinds;
several participants can propose different data within the same discussion.
Each proposed-data row sits in a typed table — `proposed_objects`,
`proposed_edits`, or `proposed_marks` — keyed to the comment that introduced
it and to the proposal discussion.

A proposal starts in `pending` state. Expert, verified, and admin users can
approve or reject any pending proposal, including their own.

Approval selects one comment as the resolution. The proposed-data rows linked
to that comment are applied to the catalogue: a `proposed_objects` row becomes
a new object, `proposed_edits` rows update the target object's fields, and a
`proposed_marks` row applies the correction. The discussion moves to
`approved`, the chosen comment is marked `is_accepted`, and the change is
written to the object's edit history. Because the discussion-level approval
determines which proposed-data rows are applied, `proposed_objects`,
`proposed_edits`, and `proposed_marks` do not carry their own status column.

**Mark-as-bad reversal mechanics.** When a mark-as-bad proposal is approved,
the effect depends on what is being reverted:
- If the target was an **edit**, the affected field on the `objects` table
  reverts to its `old_value`.
- If the target was an **object creation**, the object's `status` is set to
  `deleted`, hiding it from the public catalogue while preserving every audit
  record.

Any user can submit a mark-as-bad proposal, but only verified and admin users
can approve one.

When a proposal originates from an identification (because the ID discovered a
new object), its `parent_discussion_id` points back to the identification
discussion, so the object page shows the full chain.

### 2.4 Comments

Comments form a single flat list under each discussion. Each comment has an
anchor link for direct referencing. A comment carries `is_accepted` to mark the
discussion's chosen resolution — for an identification that is the accepted
answer, and for a proposal that is the approved data.

### 2.5 Reference syntax

Discussion bodies and comments are rendered with clickable references:

| Syntax | Links to |
|---|---|
| `@username` | User profile |
| `@obj:Sirius` | Catalogue object (by name or catalog ID) |
| `@discussion:42` | Discussion thread |
| `@comment:123` | Specific comment |

All output is HTML-escaped for safety. The reference parser runs after
`htmlspecialchars()` on the raw text to prevent HTML injection through the
reference syntax.

---

## 3. Catalogue

### 3.1 Objects

The catalogue stores astronomical objects with fields for name, catalog ID,
object type, right ascension, declination, magnitude, constellation, distance,
discoverer, discovery year, notes, and a `status` indicating whether the object
is `active` or `deleted`. Coordinates are stored as J2000 sexagesimal strings
(e.g. `06:45:08.9`, `-16:42:58`), matching the astronomical convention used by
sources such as SIMBAD and OpenNGC. `distance_ly` uses `DECIMAL(12,3)` which
supports values up to 999 billion light-years — well past the observable
universe's ~93 billion light-year diameter.

### 3.2 Object history

Every change to an object is recorded as a row in `object_edits`, which is the
object's complete edit history. Each entry records the source proposal
(`discussion_id`), the approved comment that supplied the data (`comment_id`),
the reviewer who approved it (`reviewer_id`), and the change itself
(`action`, `field`, `old_value`, `new_value`).

All actions use the same per-field row structure:
- **`created`**: one row per populated field; `old_value` is `NULL`,
  `new_value` is the initial value.
- **`edited`**: one row per changed field; `old_value` is the previous value,
  `new_value` is the proposed value.
- **`marked_bad` (reverting an edit)**: the `objects` field reverts to its
  prior value; `old_value` is the bad value, `new_value` is the reverted
  correct value.
- **`marked_bad` (reverting a creation)**: one row with `field` set to
  `status`, `old_value` set to `active`, `new_value` set to `deleted`.

An object's page draws from `object_edits` to show:
- The proposal that created it.
- Every approved edit that changed it.
- Every resolved identification linked to it (via `identified_object_id`).
- Any contributions marked as incorrect and why.

### 3.3 Direct editing

Expert and above users can edit the catalogue immediately. They create a
proposal discussion and approve it themselves, and the edit is written to
`object_edits` like any other applied change.

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

A user's net score is the number of their proposed-data rows (in
`proposed_objects`, `proposed_edits`, and `proposed_marks`) that have been
applied via an approved comment, minus the number of their proposed-data rows
that were later marked as bad. When it reaches 5, the user is promoted to
expert. Promotion is recalculated whenever a proposal is approved, rejected,
or marked bad.

### 4.3 Auto-demotion

If an expert's net score drops below 5 (due to a contribution marked bad),
they are demoted to normal. They can regain expert status by contributing
more.

Admins can also manually demote an expert. A manually demoted user cannot be
re-auto-promoted until an admin clears the restriction. The auto-promotion
query checks that `admin_demoted_at` is `NULL` before promoting.

### 4.4 Protected contributions

Only incorrect data is marked as bad. Data superseded by better measurements
remains valid — a later, more accurate measurement does not retroactively
invalidate the original contribution.

---

## 5. Audit Trail

Community contributions are recorded as discussions: proposals with their
reviews, identification resolutions, and accepted answers all live in the
discussion, comment, and proposed-data records. Every applied catalogue change
is captured row by row in `object_edits`.

Three administrative operations are recorded in `admin_actions`: creating a
user account, manually demoting a user, and granting verified status. Each
entry records who performed it, the target, and a timestamp. Verification notes
are stored separately in `user_verifications`, which is readable only by
admins.

---

## 6. Database Schema

The catalogue table `objects` is defined in `db/schema.sql`. The forum schema
in `htdocs/schema-forum.sql` defines nine tables: `users`, `discussions`,
`comments`, `proposed_objects`, `proposed_edits`, `proposed_marks`,
`object_edits`, `user_verifications`, and `admin_actions`.

### 6.1 `users`

```
id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
username            VARCHAR(64) NOT NULL UNIQUE
password            VARCHAR(255) NOT NULL
role                ENUM('admin','member') DEFAULT 'member'
expertise           ENUM('normal','expert','verified') DEFAULT 'normal'
admin_demoted_at    TIMESTAMP NULL
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
status              ENUM('active','deleted') DEFAULT 'active'
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### 6.3 `discussions`

One table holds all three discussion types. A proposal carries its approval
state, an identification carries the resolved object link, and a proposal
spawned from an identification carries the parent link back to it.

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

### 6.5 `proposed_objects`

Holds the data for a `new_object` proposal, mirroring the `objects` columns and
linking to the comment that introduced it.

```
id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
discussion_id       INT UNSIGNED NOT NULL
comment_id          INT UNSIGNED NOT NULL
author_id           INT UNSIGNED NOT NULL
name                VARCHAR(255) NOT NULL
catalog_id          VARCHAR(64) NULL
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

### 6.6 `proposed_edits`

Holds one proposed field change per row for an `edit_field` proposal.

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
discussion_id   INT UNSIGNED NOT NULL
comment_id      INT UNSIGNED NOT NULL
object_id       INT UNSIGNED NOT NULL
author_id       INT UNSIGNED NOT NULL
field           VARCHAR(64) NOT NULL
old_value       VARCHAR(255) NULL
new_value       VARCHAR(255) NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.7 `proposed_marks`

Holds a `mark_as_bad` proposal.

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
discussion_id   INT UNSIGNED NOT NULL
comment_id      INT UNSIGNED NOT NULL
object_id       INT UNSIGNED NOT NULL
author_id       INT UNSIGNED NOT NULL
reason          TEXT NOT NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.8 `object_edits`

The per-object edit history. Every row records a single field-level change.

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
object_id       INT UNSIGNED NOT NULL
discussion_id   INT UNSIGNED NOT NULL
comment_id      INT UNSIGNED NULL
action          ENUM('created','edited','marked_bad') NOT NULL
field           VARCHAR(64) NULL
old_value       VARCHAR(255) NULL
new_value       VARCHAR(255) NULL
reviewer_id     INT UNSIGNED NOT NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

Row conventions by action:

| `action` | `field` | `old_value` | `new_value` |
|---|---|---|---|
| `created` | The populated column name | `NULL` | The initial value |
| `edited` | The changed column name | The previous value | The proposed value |
| `marked_bad` (edit revert) | The reverted column name | The bad value | The reverted correct value |
| `marked_bad` (creation revert) | `status` | `active` | `deleted` |

### 6.9 `user_verifications`

Records each verification action and its private note. Admins read this table;
it is not exposed to non-admin users.

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id         INT UNSIGNED NOT NULL
verified_by_id  INT UNSIGNED NOT NULL
note            TEXT NULL
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 6.10 `admin_actions`

```
id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
admin_id        INT UNSIGNED NOT NULL
action          ENUM('create_user','demote_user','verify_user')
target_type     ENUM('user') NOT NULL
target_id       INT UNSIGNED NOT NULL
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
