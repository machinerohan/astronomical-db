-- AstroForum — Forum tables
-- MySQL / MariaDB compatible
-- Run after db/schema.sql (requires objects table)

USE astronomical_db;

-- 1. Categories (self-referencing hierarchy)
CREATE TABLE IF NOT EXISTS categories (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id     INT UNSIGNED NULL,
  name          VARCHAR(64) NOT NULL,
  slug          VARCHAR(64) NOT NULL UNIQUE,
  description   VARCHAR(255) NULL,
  sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Category ↔ entry type junction
CREATE TABLE IF NOT EXISTS category_entry_types (
  category_id  INT UNSIGNED NOT NULL,
  entry_type   VARCHAR(64) NOT NULL,
  PRIMARY KEY (category_id, entry_type),

  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Users
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(64) NOT NULL UNIQUE,
  password        VARCHAR(255) NOT NULL,
  role            ENUM('admin','member') NOT NULL DEFAULT 'member',
  expertise       ENUM('normal','expert','verified') NOT NULL DEFAULT 'normal',
  admin_demoted_at TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Replies (created before threads due to circular FK)
CREATE TABLE IF NOT EXISTS replies (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id       INT UNSIGNED NOT NULL,
  body            TEXT NOT NULL,
  author_id       INT UNSIGNED NOT NULL,
  is_solution     BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (author_id) REFERENCES users(id),
  INDEX idx_replies_thread (thread_id),
  INDEX idx_replies_author (author_id),
  INDEX idx_replies_solution (is_solution)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Threads
CREATE TABLE IF NOT EXISTS threads (
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
  FOREIGN KEY (closed_by)            REFERENCES users(id),
  INDEX idx_threads_category (category_id),
  INDEX idx_threads_status (status),
  INDEX idx_threads_proposal_status (proposal_status),
  INDEX idx_threads_is_accepted (is_accepted),
  INDEX idx_threads_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Circular FKs (both tables now exist)
ALTER TABLE replies
  ADD FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE;

ALTER TABLE threads
  ADD FOREIGN KEY (parent_reply_id) REFERENCES replies(id) ON DELETE SET NULL;

-- 7. Proposed entries (new catalogue entries from proposals)
CREATE TABLE IF NOT EXISTS proposed_entries (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Proposed field edits
CREATE TABLE IF NOT EXISTS proposed_field_edits (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Proposed removals
CREATE TABLE IF NOT EXISTS proposed_removals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Entry edits (audit log)
CREATE TABLE IF NOT EXISTS entry_edits (
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
  FOREIGN KEY (reviewer_id)       REFERENCES users(id),
  INDEX idx_entry_edits_target_author (target_author_id, action),
  INDEX idx_entry_edits_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. User verifications (admin notes)
CREATE TABLE IF NOT EXISTS user_verifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  verified_by_id  INT UNSIGNED NOT NULL,
  note            TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (user_id)       REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (verified_by_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Admin actions (audit log)
CREATE TABLE IF NOT EXISTS admin_actions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id        INT UNSIGNED NOT NULL,
  action          ENUM('create_user','demote_user','verify_user') NOT NULL,
  target_type     ENUM('user') NOT NULL,
  target_id       INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (admin_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
