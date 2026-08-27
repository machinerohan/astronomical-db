-- AstroForum database — MySQL / MariaDB compatible
-- Spec R12: this is the forum database. The astronomical catalogue lives in
-- its own database (catalogue_db, created by db/schema.sql). References from
-- forum tables to catalogue objects are LOGICAL references (plain columns,
-- no SQL FK) because MySQL cannot enforce FKs across databases safely here;
-- integrity is kept by web/functions.php.

CREATE DATABASE IF NOT EXISTS astronomical_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE astronomical_db;

-- 1. Users (R1, R5, R7, R8)
-- expertise: 'normal' | 'expert' | 'verified'. Admin-granted verification has
-- the same access level as expert (R8). promotion_source records how the
-- current level was reached ('auto' via approvals, 'admin' via verification).
CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username            VARCHAR(64)  NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  role                ENUM('admin','member') NOT NULL DEFAULT 'member',
  expertise           ENUM('normal','expert','verified') NOT NULL DEFAULT 'normal',
  promotion_source    ENUM('auto','admin') NULL,
  registration_status ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
  approved_by         INT UNSIGNED NULL,
  is_restricted       BOOLEAN NOT NULL DEFAULT FALSE,
  restricted_by       INT UNSIGNED NULL,          -- admin who restricted (R8)
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_approval (registration_status),
  CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_users_restricted_by FOREIGN KEY (restricted_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- R8: admins manually verify users ("why" is kept in note); restricting a
-- verified user is also recorded here so admins can see why.
CREATE TABLE IF NOT EXISTS verifications (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  verified_by_id INT UNSIGNED NOT NULL,
  kind           ENUM('verify','unverify','restrict','unrestrict') NOT NULL
                 DEFAULT 'verify',
  note           TEXT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verifications_user (user_id),
  CONSTRAINT fk_verifications_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_verifications_verifier FOREIGN KEY (verified_by_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- R9: one category per object type acts as its subforum. The general forum
-- has object_type NULL.
CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(64)  NOT NULL,
  slug        VARCHAR(64)  NOT NULL,
  object_type VARCHAR(64)  NULL,
  description VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_object_type (object_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- R2/R3: thread types; identified_object_id is a logical ref into
-- catalogue_db.objects(id), set when the author confirms an identification.
CREATE TABLE IF NOT EXISTS threads (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id          INT UNSIGNED NOT NULL,
  author_id            INT UNSIGNED NOT NULL,
  type                 ENUM('discussion','identification','proposal') NOT NULL
                       DEFAULT 'discussion',       -- (R2)
  title                VARCHAR(255) NOT NULL,
  status               ENUM('open','closed') NOT NULL DEFAULT 'open',
  identified_object_id INT UNSIGNED NULL,          -- set when the author confirms (R3)
  linked_proposal_id   INT UNSIGNED NULL,          -- set after threads exist (circular, filled in app)
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_threads_category (category_id),
  KEY idx_threads_author (author_id),
  CONSTRAINT fk_threads_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_threads_author   FOREIGN KEY (author_id)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- R2/R3/R9: replies. Opening post of an identification thread is the only
-- place identification may be asked for; the app enforces that and the author
-- confirm action flips threads.identified_object_id + marks is_solution.
CREATE TABLE IF NOT EXISTS posts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id        INT UNSIGNED NOT NULL,
  author_id        INT UNSIGNED NOT NULL,
  body             TEXT NOT NULL,
  is_opening       BOOLEAN NOT NULL DEFAULT FALSE,
  is_solution      BOOLEAN NOT NULL DEFAULT FALSE,
  linked_post_id   INT UNSIGNED NULL,             -- reply-to link (R9 message links)
  linked_object_id INT UNSIGNED NULL,             -- logical ref into catalogue_db.objects(id)
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_posts_thread (thread_id),
  KEY idx_posts_author (author_id),
  CONSTRAINT fk_posts_thread FOREIGN KEY (thread_id) REFERENCES threads(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_posts_linked_post FOREIGN KEY (linked_post_id) REFERENCES posts(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- R4/R5: proposals to add or change catalogue objects. Approved ones modify
-- catalogue_db.objects (app-side) and leave an audit row in object_edits.
CREATE TABLE IF NOT EXISTS proposals (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id        INT UNSIGNED NOT NULL,
  post_id          INT UNSIGNED NULL,             -- reply carrying the formal proposal details
  author_id        INT UNSIGNED NOT NULL,
  type             ENUM('add_entry','edit_field') NOT NULL,
  target_object_id INT UNSIGNED NULL,             -- logical ref, required for edit_field
  field            VARCHAR(64)  NULL,
  new_value        VARCHAR(255) NULL,
  status           ENUM('pending','approved','rejected','reverted') NOT NULL DEFAULT 'pending',
  approver_id      INT UNSIGNED NULL,             -- expert/verified/admin who approved (R5)
  reason           VARCHAR(255) NULL,             -- rejection reason -> reply message (R9)
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at      TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_proposals_thread (thread_id),
  KEY idx_proposals_author (author_id),
  KEY idx_proposals_status (status),
  CONSTRAINT fk_proposals_thread   FOREIGN KEY (thread_id) REFERENCES threads(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_proposals_author   FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_proposals_approver FOREIGN KEY (approver_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payload of an add_entry proposal until approval creates the catalogue row.
CREATE TABLE IF NOT EXISTS proposed_objects (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id     INT UNSIGNED NOT NULL,
  name            VARCHAR(255) NOT NULL,
  object_type     VARCHAR(64)  NOT NULL,
  right_ascension VARCHAR(16)  NULL,
  declination     VARCHAR(16)  NULL,
  apparent_mag    DECIMAL(6,3) NULL,
  constellation   VARCHAR(16)  NULL,
  distance_ly     DECIMAL(12,3) NULL,
  discovered_by   VARCHAR(128) NULL,
  discovery_year  SMALLINT UNSIGNED NULL,
  notes           TEXT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_proposed_objects_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail of applied changes; powers revert-to-last-good (R6).
CREATE TABLE IF NOT EXISTS object_edits (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_id   INT UNSIGNED NOT NULL,              -- logical ref into catalogue_db.objects(id)
  proposal_id INT UNSIGNED NOT NULL,
  field       VARCHAR(64)  NULL,
  old_value   VARCHAR(255) NULL,
  new_value   VARCHAR(255) NULL,
  applied_by  INT UNSIGNED NOT NULL,              -- approving expert (R5)
  reverted    BOOLEAN NOT NULL DEFAULT FALSE,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_object_edits_object (object_id),
  KEY idx_object_edits_proposal (proposal_id),
  CONSTRAINT fk_object_edits_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_object_edits_applier  FOREIGN KEY (applied_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- R6: disputes against approved proposals. Users other than the original
-- approver resolve them; approval reverts the change (or deletes the added
-- entry). Every resolved-for-revert counts toward R7 demotion of the original
-- approver's expert status.
-- Resolver rules enforced by triggers below (CHECK unusable here: SET NULL FK).
CREATE TABLE IF NOT EXISTS disputes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NOT NULL,              -- disputing user
  reason      TEXT NOT NULL,
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  resolver_id INT UNSIGNED NULL,                  -- differs from original approver & disputant
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_disputes_proposal (proposal_id),
  KEY idx_disputes_author (author_id),
  CONSTRAINT fk_disputes_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_disputes_author   FOREIGN KEY (author_id)   REFERENCES users(id),
  CONSTRAINT fk_disputes_resolver FOREIGN KEY (resolver_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

-- R5: nobody approves their own proposal.
CREATE TRIGGER trg_proposals_no_self_approve
BEFORE UPDATE ON proposals
FOR EACH ROW
BEGIN
  IF NEW.approver_id IS NOT NULL AND NEW.approver_id = NEW.author_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'a proposal cannot be approved by its own author';
  END IF;
END$$

-- R6: dispute resolution may not be done by the proposal approver, nor by the
-- dispute's own filer.
CREATE TRIGGER trg_disputes_no_original_approver_insert
BEFORE INSERT ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_approver INT UNSIGNED;
  DECLARE v_author INT UNSIGNED;
  SELECT approver_id INTO v_approver FROM proposals WHERE id = NEW.proposal_id;
  SELECT author_id INTO v_author FROM proposals WHERE id = NEW.proposal_id;
  IF NEW.resolver_id IS NOT NULL AND (NEW.resolver_id <=> v_approver OR NEW.resolver_id <=> NEW.author_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'dispute resolver must differ from proposal approver and disputant';
  END IF;
END$$

CREATE TRIGGER trg_disputes_no_original_approver_update
BEFORE UPDATE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_approver INT UNSIGNED;
  DECLARE v_author INT UNSIGNED;
  SELECT approver_id INTO v_approver FROM proposals WHERE id = NEW.proposal_id;
  SELECT author_id INTO v_author FROM proposals WHERE id = NEW.proposal_id;
  IF NEW.resolver_id IS NOT NULL AND (NEW.resolver_id <=> v_approver OR NEW.resolver_id <=> NEW.author_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'dispute resolver must differ from proposal approver and disputant';
  END IF;
END$$

DELIMITER ;
