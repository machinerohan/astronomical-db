# AstroForum — Specification

An astronomy discussion forum with a community-curated catalogue of
celestial objects. It functions as a structured forum where categories
map to object types in the catalogue, and threads propose changes to
the catalogue itself.

---

## 1. Forum

### 1.1 Categories (sub-forums)

Every thread lives in exactly one category. Categories form a
two-level hierarchy: top-level categories (`general`, `identifications`,
and object-type groups) each have a child `proposals` sub-category.

| Category                  | Slug                 | Parent   | Entry types mapped            |
|---------------------------|----------------------|----------|-------------------------------|
| General                   | `general`            | NULL     | NULL                          |
| Help Identifying          | `identifications`    | NULL     | NULL                          |
| Stars                     | `stars`              | NULL     | star                          |
| ├ Stars — Proposals       | `stars-proposals`    | `stars`  | star                          |
| Nebulae & Clusters        | `nebulae-clusters`   | NULL     | nebula, cluster, etc.         |
| ├ Nebulae — Proposals     | `nebulae-proposals`  | `nebulae-clusters` | nebula, cluster, etc.|
| Galaxies                  | `galaxies`           | NULL     | galaxy, quasar                |
| ├ Galaxies — Proposals    | `galaxies-proposals` | `galaxies` | galaxy, quasar               |
| Solar System              | `solar-system`       | NULL     | planet, moon, asteroid, comet |
| ├ Solar System — Proposals| `solar-proposals`    | `solar-system` | planet, moon, etc.       |
| Deep Sky                  | `deep-sky`           | NULL     | anything not matched above    |
| ├ Deep Sky — Proposals    | `deep-sky-proposals` | `deep-sky` | anything not matched above   |

Categories are stored in a `categories` table with a self-referencing
`parent_id` FK. The index page renders parent categories with their
child sub-categories indented beneath them (standard forum index
pattern), each showing its unread/recent thread count.

Because a category (like `nebulae-clusters`) can map to multiple object
types (e.g., `nebula`, `planetary_nebula`, `open_cluster`), the 1:N
mapping is stored in a junction table: `category_entry_types`. The
junction table exists for data normalization and future use (e.g., a
catalogue filter keyed to the current category), but the category page
itself is a thread listing — it does not implement a per-category
catalogue sidebar.

When a thread is created in a category that has mapped entry types,
the system automatically links the thread to that catalogue type
context. This includes both parent categories (regular discussion) and
their proposal sub-categories (proposal threads). No dropdown picker
needed.

A thread in a proposal sub-category is always a proposal thread — the
category determines the proposal type context, though the `proposal_type`
column on the thread disambiguates between `add_entry`, `edit_field`,
and `remove_entry`.

New top-level categories and sub-categories can be added by an admin.

### 1.2 Threads

Threads are the basic unit of discussion. A thread has:

- A title and body (body is plain text with reference syntax)
- An author
- A category (FK to `categories`)
- An optional link to a catalogue entry (FK `entry_id`) — set when the
  thread is explicitly about a known object. The entry detail page lists
  all threads linked this way.
- A status: `open` or `closed`
- `is_accepted` boolean — set if the OP's initial post is selected as
  the approved proposal data (see §1.5).
- A created-at timestamp

Closing a thread locks it — no new replies can be posted. Admins and
the thread author can close a thread. Any user can close their own
thread by marking a reply as the solution (see §1.4). Approving or
rejecting a proposal automatically closes the thread.

**Proposal threads** (category = `proposals`) carry extra state:
- `proposal_type` — `add_entry`, `edit_field`, or `remove_entry`
- `proposal_status` — `pending`, `approved`, or `rejected`

**Identification threads** (category = `identifications`) carry:
- `identified_entry_id` — FK to the catalogue entry identified (set
  when solved)

### 1.3 Replies

Replies are a flat list under each thread, in chronological order.
Each reply has:

- An author
- A body (plain text with reference syntax)
- `is_solution` boolean — set when the OP or an admin marks this
  reply as the correct answer (identification threads only, or when
  selected as the approved proposal data)
- A created-at timestamp

Reply authors (and the OP during thread creation) can optionally
attach structured proposal data (a new entry's fields, or a target
entry + field change). The form to do so appears inline when composing
a reply inside the `proposals` category, and during thread creation
for the OP. This keeps proposals attached to a specific post so multiple
participants can suggest different data within the same thread without
overwriting each other. The data is stored in `proposed_*` tables
keyed to `reply_id` (or `thread_id` with a `NULL` `reply_id` if it
belongs to the OP).

### 1.4 Marking a solution (identification threads)

In an identification thread, the original poster (or an admin) can
mark any reply as the solution. This sets `is_solution = true` on
that reply and closes the thread.

If the identification points to an existing catalogue entry, the
thread is linked to it via `identified_entry_id`. The system checks
the solution reply for `@entry:` mentions to auto-link the existing
entry. If the reply mentions no known entry, the solver (or the OP)
manually creates a proposal thread in the relevant proposals
sub-category with `parent_reply_id` pointing to the solution reply.
`identified_entry_id` stays NULL on the ident thread until the
proposal is approved and the entry exists, at which point whoever
reviews the proposal sets `identified_entry_id` to the newly created
entry. This preserves the full chain: ident thread → solution reply
→ proposal → approved entry.

The UI is a simple "Mark as solution" button on each reply, visible
only to the thread author and admins. No dropdown, no object selector.
If the solver wants to link to an existing entry, they write
`@entry:Sirius` in their reply and the system picks it up when the
solution is marked. If the reply mentions no known entry, the system
offers to create a proposal thread.

### 1.5 Proposals (catalogue changes)

A thread in a proposal sub-category (e.g. `stars-proposals`) suggests
a change to the catalogue. Three types:

- **add_entry** — propose a new object with all its fields
- **edit_field** — propose changing a specific field on an existing
  entry
- **remove_entry** — propose soft-deleting an existing entry, or
  reverting a specific incorrect field edit (with reason)

Any user can start a proposal thread. The thread body explains the
motivation. Then, any participant (including the OP during thread
creation) can attach structured proposal data (the full entry for
`add_entry`, or target + field for `edit_field`/`remove_entry`).
Each post carries its own proposal data, so multiple people can
propose different values and the thread preserves the full history.

**Review process:**
- A proposal is `pending` on creation
- Expert, verified, and admin users see "Approve" and "Reject"
  buttons at the top of the thread
- On approval, the reviewer selects **which post's data** to apply
  via a simple dropdown. If a reply is selected, its `proposed_*`
  rows are applied and it gets `is_solution = 1`. If the OP's initial
  post is selected, the thread gets `is_accepted = 1` and its
  `proposed_*` rows (where `reply_id` is NULL) are applied.
- The selected data is written to `entry_edits`.
- Rejection sets the thread to `rejected`; no catalogue changes
- Moderators can leave a reply explaining their decision
- Both approval and rejection automatically close the thread to
  further replies.

**For remove_entry:** only verified and admin users can approve a
removal, since it is a destructive action. When approved, the effect
depends on what is being reverted:
- If the target was a specific **edit** (identified via `target_field`
  in `proposed_removals`), the system looks up the most recent valid
  value for that field prior to the bad edit, reverts the field on
  the `objects` table, and logs the reversal in `entry_edits`.
- If the target was an **entry creation**, the entry's `status` is
  set to `deleted` (data is never actually removed), hiding it from
  the public catalogue while preserving every audit record.

### 1.6 Reference syntax

Thread bodies and replies support mentions that are rendered as
clickable links:

| Syntax                | Links to                          |
|-----------------------|-----------------------------------|
| `@username`           | User profile                      |
| `@entry:Sirius`       | Catalogue entry (by name or ID)   |
| `@thread:42`          | Another thread                    |
| `@reply:123`          | A specific reply in any thread    |

All output is HTML-escaped. The reference parser runs after
`htmlspecialchars()` to prevent injection through the syntax. The
username parser uses a whitespace/start-of-string boundary to avoid
matching email addresses.

### 1.7 Closed threads

A closed thread is read-only. No new replies can be posted. Any user
can see the close reason displayed at the bottom of the thread.

---

## 2. Users

### 2.1 Accounts

Only admins can create accounts. The admin sets an initial password
(shown once, to share out of band). Every user changes their own
password through the normal password-change feature.

### 2.2 Roles

| Role     | Permissions                                                                 |
|----------|-----------------------------------------------------------------------------|
| `admin`  | Create/manage users, grant verified badge, demote experts, close threads,   |
|          | mark any solution, manage categories                                        |
| `member` | Create threads, reply, propose catalogue changes, mark solution on own      |
|          | identification threads                                                      |

### 2.3 Expertise badges

Independent of role, every user carries an expertise badge:

| Badge     | How earned                              | Powers                           |
|-----------|------------------------------------------|----------------------------------|
| `normal`  | Default                                  | Base permissions only            |
| `expert`  | Auto-promoted at net score ≥ 5           | Approve/reject proposals         |
| `verified`| Manually granted by admin                | Approve/reject proposals,        |
|           |                                          | approve `remove_entry` proposals |

An admin carries an expertise badge like any other user; it is visible
on their profile.

### 2.4 Verification notes

When granting verified status, an admin may attach a private note
(e.g., "confirmed astronomer — checked LinkedIn"). These notes are
stored in a `user_verifications` table, visible only to admins.

### 2.5 Profile page

Each user profile shows:
- Username, role, and expertise badges
- Contribution stats (threads started, solutions provided, proposals
  submitted, approved proposals, proposals that led to removals)
- Date joined
- Link to change password (for the account owner)

### 2.6 Auto-promotion (expert)

Net score = approved proposals (counted per thread where the user's
proposal data was accepted) − proposals that were later removed
(counted per `entry_edits` row in a remove_entry that targeted one of
this user's contributions). When net
score reaches 5, the user is promoted to `expert`. Recalculated
whenever a proposal is approved, rejected, or applied as a removal.

To avoid complex, multi-level SQL traces when calculating the negative
score, `entry_edits` includes a denormalized `target_author_id` column.
When a `remove_entry` proposal is approved, the system looks up the
author of the original contribution being reverted and stores their ID
in `target_author_id`. The negative score query is then a fast, indexed
lookup: `SELECT COUNT(*) FROM entry_edits WHERE action = 'removed' AND target_author_id = ?`.

Similarly, the positive score query checks how many threads
(`proposal_status = 'approved'`) or `threads.is_accepted = 1` (for
OP's proposal) the user contributed to. Indexes exist on
`threads(is_accepted)` and `replies(is_solution)`.

### 2.7 Auto-demotion

If an expert's net score drops below 5 (due to a removal targeting
their work), they are demoted to `normal`. They regain expert by
contributing more.

Admins can also manually demote an expert. A manually demoted user
cannot be re-auto-promoted until an admin clears the restriction.

### 2.8 Permissions matrix

| Action                             | normal | expert | verified | admin |
|------------------------------------|--------|--------|----------|-------|
| Create threads, reply              | ✓      | ✓      | ✓        | ✓     |
| Propose catalogue change           | ✓      | ✓      | ✓        | ✓     |
| Mark solution on own thread        | ✓      | ✓      | ✓        | ✓     |
| Mark solution on any thread        | —      | —      | —        | ✓     |
| Approve/reject proposals           | —      | ✓      | ✓        | ✓     |
| Approve `remove_entry` proposals   | —      | —      | ✓        | ✓     |
| Close any thread                   | —      | —      | —        | ✓     |
| Manage users, demote, verify       | —      | —      | —        | ✓     |
| Read/write verification notes      | —      | —      | —        | ✓     |

### 2.9 Protected contributions

Only incorrect data should be flagged for removal. Data superseded by
better measurements remains valid — a later, more accurate measurement
does not retroactively invalidate the original contribution. This is a
policy rule enforced by reviewers, not a system constraint.

---

## 3. Catalogue

### 3.1 Entries

The catalogue stores celestial objects (called "entries" in the schema
for disambiguation). Each entry has:

| Column            | Type            | Description                              |
|-------------------|-----------------|------------------------------------------|
| name              | VARCHAR(255)    | Common name (e.g. "Sirius")              |
| catalog_id        | VARCHAR(64)     | Standard catalogue ID (HR 2491, M31)     |
| entry_type        | VARCHAR(64)     | star, galaxy, nebula, cluster, etc.      |
| right_ascension   | VARCHAR(16)     | J2000 sexagesimal (06:45:08.9)           |
| declination       | VARCHAR(16)     | J2000 sexagesimal (−16:42:58)            |
| apparent_mag      | DECIMAL(6,3)    | Apparent magnitude                       |
| constellation     | VARCHAR(16)     | 3-letter abbreviation (CMa, And, Ori)    |
| distance_ly       | DECIMAL(12,3)   | Distance in light-years                  |
| discovered_by     | VARCHAR(128)    | Discoverer name                          |
| discovery_year    | SMALLINT UNSIGNED | Year of discovery                      |
| notes             | TEXT            | Free-form notes                          |
| status            | ENUM            | `active` or `deleted`                    |

Coordinates are stored as J2000 sexagesimal strings, matching the
astronomical convention used by SIMBAD and OpenNGC. `distance_ly`
uses `DECIMAL(12,3)`, supporting values up to 999 billion light-years.

### 3.2 Category entry-type mapping

Each category maps to one or more entry types via the `category_entry_types`
junction table. Child (proposal) categories copy their parent's mapped
entry types at creation time. 

| Category              | entry_type value(s)                                      |
|-----------------------|----------------------------------------------------------|
| `stars`               | star                                                     |
| `nebulae-clusters`    | nebula, emission_nebula, reflection_nebula,               |
|                       | planetary_nebula, open_cluster, globular_cluster          |
| `galaxies`            | galaxy, quasar                                           |
| `solar-system`        | planet, dwarf_planet, moon, asteroid, comet              |
| `deep-sky`            | anything not matched above                               |

Proposal sub-categories use the same entry types as their parent,
so the sidebar filters identically. 

Entries with `status = 'deleted'` are excluded from listings but
their data is preserved for audit.

### 3.3 Edit history

Every change to a catalogue entry is recorded row by row in
`entry_edits`. Each row records:

- The source proposal thread and reply (if applicable)
- The reviewer who approved it
- The target author (the original contributor whose work is being
  affected, used for fast auto-promotion negative-score lookups)
- The action: `created`, `edited`, or `removed`
- The field name, old value, and new value

An entry's detail page shows:
- The proposal thread that created it
- Every approved edit that changed it
- Every resolved identification linked to it
- Any removals applied to it and why

### 3.4 Proposal-data tables

Three tables hold pending proposal data, keyed to the thread and
optionally to a specific reply:

- **`proposed_entries`** — mirrors the `objects` table; holds the
  full record for an `add_entry` proposal
- **`proposed_field_edits`** — one row per field being changed in
  an `edit_field` proposal; stores entry_id, field, old_value,
  new_value
- **`proposed_removals`** — one row per target entry in a
  `remove_entry` proposal; stores entry_id, target_field (if reverting
  a specific edit), and reason

These tables do not carry their own status — the thread's
`proposal_status` determines whether the data is pending, applied,
or rejected. When a proposal is approved, the rows linked to the
accepted post (either OP or a specific reply) are applied; rows
linked to non-accepted posts within the same thread are ignored.

---

## 4. Database Schema

### 4.1 Base catalogue (`db/schema.sql`)

```sql
-- Provided separately. Contains:
CREATE TABLE objects (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  catalog_id      VARCHAR(64) NULL UNIQUE,
  entry_type      VARCHAR(64) NOT NULL,
  right_ascension VARCHAR(16) NULL,
  declination     VARCHAR(16) NULL,
  apparent_mag    DECIMAL(6,3) NULL,
  constellation   VARCHAR(16) NULL,
  distance_ly     DECIMAL(12,3) NULL,
  discovered_by   VARCHAR(128) NULL,
  discovery_year  SMALLINT UNSIGNED NULL,
  notes           TEXT NULL,
  status          ENUM('active','deleted') DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 4.2 Forum schema (`htdocs/schema-forum.sql`)

**categories**
```sql
CREATE TABLE categories (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id     INT UNSIGNED NULL,
  name          VARCHAR(64) NOT NULL,
  slug          VARCHAR(64) NOT NULL UNIQUE,
  description   VARCHAR(255) NULL,
  sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
);
```

**category_entry_types** (Junction table for 1:N category mappings)
```sql
CREATE TABLE category_entry_types (
  category_id  INT UNSIGNED NOT NULL,
  entry_type   VARCHAR(64) NOT NULL,
  PRIMARY KEY (category_id, entry_type),

  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
```

**users**
```sql
CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(64) NOT NULL UNIQUE,
  password        VARCHAR(255) NOT NULL,
  role            ENUM('admin','member') NOT NULL DEFAULT 'member',
  expertise       ENUM('normal','expert','verified') NOT NULL DEFAULT 'normal',
  admin_demoted_at TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

**replies** — created before `threads` to resolve the circular FK
dependency; `replies.thread_id` FK is added via ALTER TABLE after
threads exist.
```sql
CREATE TABLE replies (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id       INT UNSIGNED NOT NULL,
  body            TEXT NOT NULL,
  author_id       INT UNSIGNED NOT NULL,
  is_solution     BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (author_id) REFERENCES users(id)
);

-- Performance indexes
CREATE INDEX idx_replies_thread ON replies (thread_id);
CREATE INDEX idx_replies_author ON replies (author_id);
CREATE INDEX idx_replies_solution ON replies (is_solution);
```

**threads**
```sql
CREATE TABLE threads (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id           INT UNSIGNED NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  body                  TEXT NOT NULL,
  author_id             INT UNSIGNED NOT NULL,
  entry_id              INT UNSIGNED NULL COMMENT 'direct link to a catalogue entry',
  status                ENUM('open','closed') NOT NULL DEFAULT 'open',
  is_accepted           BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'True if OP proposal data was approved',

  -- Proposal columns (NULL for non-proposal threads)
  proposal_type         ENUM('add_entry','edit_field','remove_entry') NULL,
  proposal_status       ENUM('pending','approved','rejected') NULL,
  reviewer_id           INT UNSIGNED NULL,
  reviewed_at           DATETIME NULL,

  -- Identification column (NULL for non-identification threads)
  identified_entry_id   INT UNSIGNED NULL,

  -- Link: a proposal spawned from a solution reply on an identification thread
  parent_reply_id       INT UNSIGNED NULL,

  -- Closing
  closed_by             INT UNSIGNED NULL,
  closed_reason         VARCHAR(255) NULL,

  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (category_id)          REFERENCES categories(id),
  FOREIGN KEY (author_id)            REFERENCES users(id),
  FOREIGN KEY (entry_id)             REFERENCES objects(id) ON DELETE SET NULL,
  FOREIGN KEY (reviewer_id)          REFERENCES users(id),
  FOREIGN KEY (identified_entry_id)  REFERENCES objects(id) ON DELETE SET NULL,
  FOREIGN KEY (closed_by)            REFERENCES users(id)
);

-- Performance indexes
CREATE INDEX idx_threads_category ON threads (category_id);
CREATE INDEX idx_threads_status ON threads (status);
CREATE INDEX idx_threads_proposal_status ON threads (proposal_status);
CREATE INDEX idx_threads_is_accepted ON threads (is_accepted);
CREATE INDEX idx_threads_author ON threads (author_id);

-- Circular FK added after both tables exist:
-- ALTER TABLE replies     ADD FOREIGN KEY (thread_id)      REFERENCES threads(id) ON DELETE CASCADE;
-- ALTER TABLE threads     ADD FOREIGN KEY (parent_reply_id) REFERENCES replies(id) ON DELETE SET NULL;
```

**proposed_entries**
```sql
CREATE TABLE proposed_entries (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id           INT UNSIGNED NOT NULL,
  reply_id            INT UNSIGNED NULL COMMENT 'NULL if data belongs to OP',
  author_id           INT UNSIGNED NOT NULL,
  name                VARCHAR(255) NOT NULL,
  catalog_id          VARCHAR(64) NULL,
  entry_type          VARCHAR(64) NOT NULL,
  right_ascension     VARCHAR(16) NULL,
  declination         VARCHAR(16) NULL,
  apparent_mag        DECIMAL(6,3) NULL,
  constellation       VARCHAR(16) NULL,
  distance_ly         DECIMAL(12,3) NULL,
  discovered_by       VARCHAR(128) NULL,
  discovery_year      SMALLINT UNSIGNED NULL,
  notes               TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_id)  REFERENCES replies(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES users(id)
);
```

**proposed_field_edits**
```sql
CREATE TABLE proposed_field_edits (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id       INT UNSIGNED NOT NULL,
  reply_id        INT UNSIGNED NULL COMMENT 'NULL if data belongs to OP',
  entry_id        INT UNSIGNED NULL,
  author_id       INT UNSIGNED NOT NULL,
  field           VARCHAR(64) NOT NULL,
  old_value       VARCHAR(255) NULL,
  new_value       VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_id)  REFERENCES replies(id) ON DELETE CASCADE,
  FOREIGN KEY (entry_id)  REFERENCES objects(id) ON DELETE SET NULL,
  FOREIGN KEY (author_id) REFERENCES users(id)
);
```

**proposed_removals**
```sql
CREATE TABLE proposed_removals (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id       INT UNSIGNED NOT NULL,
  reply_id        INT UNSIGNED NULL COMMENT 'NULL if data belongs to OP',
  entry_id        INT UNSIGNED NULL,
  target_field    VARCHAR(64) NULL COMMENT 'Specific field being reverted (NULL if removing whole entry)',
  author_id       INT UNSIGNED NOT NULL,
  reason          TEXT NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_id)  REFERENCES replies(id) ON DELETE CASCADE,
  FOREIGN KEY (entry_id)  REFERENCES objects(id) ON DELETE SET NULL,
  FOREIGN KEY (author_id) REFERENCES users(id)
);
```

**entry_edits**
```sql
CREATE TABLE entry_edits (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id        INT UNSIGNED NULL,
  thread_id       INT UNSIGNED NOT NULL,
  reply_id        INT UNSIGNED NULL,
  target_author_id INT UNSIGNED NULL COMMENT 'Original author being reverted, for fast demotion queries',
  action          ENUM('created','edited','removed') NOT NULL,
  field           VARCHAR(64) NULL,
  old_value       VARCHAR(255) NULL,
  new_value       VARCHAR(255) NULL,
  reviewer_id     INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (entry_id)          REFERENCES objects(id) ON DELETE SET NULL,
  FOREIGN KEY (thread_id)         REFERENCES threads(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_id)          REFERENCES replies(id) ON DELETE SET NULL,
  FOREIGN KEY (target_author_id)  REFERENCES users(id),
  FOREIGN KEY (reviewer_id)       REFERENCES users(id)
);

-- Index for auto-demotion queries
CREATE INDEX idx_entry_edits_target_author ON entry_edits (target_author_id, action);
```

Row conventions:

| `action`  | `field`            | `old_value`               | `new_value`              |
|-----------|--------------------|---------------------------|--------------------------|
| `created` | populated col name | NULL                      | initial value            |
| `edited`  | changed col name   | previous value            | proposed value           |
| `removed` (edit revert) | reverted col | bad value                 | reverted correct value   |
| `removed` (entry delete) | `status`      | `active`                  | `deleted`                |

**user_verifications**
```sql
CREATE TABLE user_verifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  verified_by_id  INT UNSIGNED NOT NULL,
  note            TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (user_id)       REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (verified_by_id) REFERENCES users(id)
);
```

**admin_actions**
```sql
CREATE TABLE admin_actions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id        INT UNSIGNED NOT NULL,
  action          ENUM('create_user','demote_user','verify_user') NOT NULL,
  target_type     ENUM('user') NOT NULL,
  target_id       INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (admin_id) REFERENCES users(id)
);
```

---

## 5. Application Structure

### 5.1 Page layout

```
htdocs/
├── index.php                 — Forum index: category list with thread counts
├── category.php              — Thread listing for one category
├── thread.php                — View a thread with its replies
├── new-thread.php            — Create a new thread
├── login.php
├── logout.php
├── change-password.php
├── profile.php               — User profile
├── entry.php                 — Single catalogue entry detail + edit history
├── catalogue.php             — Browse/search all catalogue entries
├── includes/
│   ├── db.php                — PDO connection (127.0.0.1:3306)
│   ├── auth.php              — Session, login, permissions, expertise calc
│   ├── functions.php         — h(), render_body(), time_ago(), flash_message()
│   ├── header.php            — HTML shell + nav
│   └── footer.php            — Close tags
├── admin/
│   ├── index.php             — Dashboard
│   ├── users.php             — User list + manage
│   ├── create-user.php       — Create account
│   ├── proposals.php         — Pending proposals queue
│   └── contributions.php     — Browse contribution history
├── schema-forum.sql          — All forum tables (CREATE statements)
└── style.css                 — Stylesheet
```

### 5.2 Key differences from a plain-thread forum

1. **Category hierarchy encodes object type.** The `category.php` page
   lists threads for the current category. The `category_entry_types`
   junction table exists for data normalization (no comma-separated
   types) and future catalogue-filtering features. Proposal sub-categories
   sit under their parent in the nav tree.

2. **Posts can carry structured proposal data.** When composing a
   reply inside the `proposals` category, or creating the thread itself,
   a form appears below the textarea for attaching entry fields, a
   target + field name for edits, or a target + reason for removals.
   This data is stored in the `proposed_*` tables keyed to `reply_id`
   (or `thread_id` with `NULL` `reply_id` for OP), so multiple posts
   can carry different proposals within the same thread.

3. **Solution marking on identifications is thread-level.** The OP
   clicks "Mark as solution" on a reply, which sets `is_solution = 1`
   and checks the reply for `@entry:` mentions to auto-link the
   thread. If no known entry is mentioned, the solver manually creates
   a proposal thread in the relevant proposals sub-category.

4. **Approve/Reject on proposals is thread-level, but data is
   per-post.** Experts see Approve/Reject buttons at the top of the
   thread. On approval, a dropdown lets them pick which post's data
   to apply (OP or a specific reply). Only that post's `proposed_*`
   rows are written to the catalogue and `entry_edits`. The chosen
   post gets `is_accepted = 1` (if OP) or `is_solution = 1` (if reply).
   Both approval and rejection automatically close the thread.

### 5.3 Reference syntax implementation

The render function processes post body in this order:
1. `htmlspecialchars()` for safety
2. Regex replacements for @mentions
3. `nl2br()` for line breaks

```php
function render_body(PDO $pdo, string $text): string
{
    $text = h($text);
    
    // Matches @username only if preceded by start of string or whitespace
    $text = preg_replace('/(?<=\s|^)@(?!entry:|thread:|reply:)([A-Za-z0-9_]+)/',
        '<a href="profile.php?username=$1">@$1</a>', $text);
        
    $text = preg_replace_callback('/@entry:([^\s<>]+)/',
        function($m) {
            return '<a href="entry.php?q=' . urlencode($m[1]) . '">@entry:' . h($m[1]) . '</a>';
        }, $text);
        
    $text = preg_replace('/@thread:(\d+)/',
        '<a href="thread.php?id=$1">@thread:$1</a>', $text);
        
    $text = preg_replace('/@reply:(\d+)/',
        '<a href="thread.php?rid=$1#reply-$1">@reply:$1</a>', $text);
        
    return nl2br($text, false);
}
```

---

## 6. Local Development

### Windows — XAMPP

```
1. Install XAMPP, start Apache + MySQL
2. mysql -u root < db\schema.sql
3. mysql -u root < db\seed.sql
4. mysql -u root < htdocs\schema-forum.sql
5. mysql -u root < htdocs\schema-forum-seed.sql
6. Place htdocs/ into C:\xampp\htdocs\astroforum\
7. Visit http://localhost/astroforum/
```

### Linux — Nix

```
nix develop
mysql < db/schema.sql
mysql < db/seed.sql
mysql < htdocs/schema-forum.sql
mysql < htdocs/schema-forum-seed.sql
php -S localhost:8080 -t htdocs/
```

Default accounts seeded: `admin`/`admin` (admin, verified), `alice`/`password` (member, normal).
Registration is admin-only; no public signup.
