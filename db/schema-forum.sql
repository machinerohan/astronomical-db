-- AstroForum — forum schema, derived from SPEC.md (simple branch).
-- MySQL / MariaDB compatible. Run AFTER db/schema.sql (which creates `objects`).
--
-- How each SPEC requirement is satisfied:
--   R1  Admins approve new registrations ........ users.registration_status, users.approved_by
--   R2  Threads are discussion or identification  threads.type
--   R3  Only the opening post asks for identification; the thread author
--       confirms the object ....................... posts.is_opening, posts.is_solution,
--                                               threads.identified_object_id
--   R4  Threads can carry proposals to add or
--       change objects ............................ proposals, proposed_objects
--   R5  Experts approve proposals; a good approval
--       history promotes a user to expert ......... proposals.approver_id, object_edits,
--                                               users.expertise (computed)
--   R6  A proposal can be disputed; the dispute is
--       approved by someone OTHER than the original
--       approver, reverting to the last good value  disputes, object_edits (revert), trigger
--   R7  Enough reverts demote an expert ............ users.expertise (computed from object_edits)
--   R8  Admins verify users (expert-level access),
--       restrict verified users, and record why .... verifications, users.is_restricted
--   R9  Subforums per object type; approval,
--       rejection, and identification replies link
--       back to the relevant approval reply ........ categories.object_type,
--                                               posts.linked_post_id, posts.linked_object_id
--   R10 Profile page with history .................. all tables keyed by author/user id
--
-- Self-approval is removed everywhere:
--   * a proposal author may not approve their own proposal   (CHECK)
--   * a dispute author may not resolve their own dispute     (CHECK)
--   * the original approver may not resolve a dispute        (trigger)

USE astronomical_db;

-- 1. Users
CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username            VARCHAR(64)  NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  role                ENUM('admin','member') NOT NULL DEFAULT 'member',
  expertise           ENUM('normal','expert','verified') NOT NULL DEFAULT 'normal',
  registration_status ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
  approved_by         INT UNSIGNED NULL,           -- admin who approved registration (R1)
  is_restricted       BOOLEAN NOT NULL DEFAULT FALSE, -- admin restriction on a verified user (R8)
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_approval (registration_status),
  CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Verification notes (R8: admins can see why a user was verified)
CREATE TABLE IF NOT EXISTS verifications (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  verified_by_id INT UNSIGNED NOT NULL,            -- admin who granted the badge
  note           TEXT NULL,                        -- private reason, admin-only
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verifications_user (user_id),
  CONSTRAINT fk_verifications_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_verifications_verifier FOREIGN KEY (verified_by_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Subforums, one per object type (R9)
CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(64)  NOT NULL,
  slug        VARCHAR(64)  NOT NULL,
  object_type VARCHAR(64)  NULL,                   -- NULL for general/discussion subforums
  description VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_object_type (object_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Threads
CREATE TABLE IF NOT EXISTS threads (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id          INT UNSIGNED NOT NULL,
  author_id            INT UNSIGNED NOT NULL,
  type                 ENUM('discussion','identification','proposal') NOT NULL
                       DEFAULT 'discussion',       -- (R2)
  title                VARCHAR(255) NOT NULL,
  status               ENUM('open','closed') NOT NULL DEFAULT 'open',
  identified_object_id INT UNSIGNED NULL,          -- set when the author confirms (R3)
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_threads_category (category_id),
  KEY idx_threads_author (author_id),
  CONSTRAINT fk_threads_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_threads_author   FOREIGN KEY (author_id)   REFERENCES users(id),
  CONSTRAINT fk_threads_identified_object FOREIGN KEY (identified_object_id)
    REFERENCES objects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Posts (replies). The opening post is the only one that asks for identification.
CREATE TABLE IF NOT EXISTS posts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id        INT UNSIGNED NOT NULL,
  author_id        INT UNSIGNED NOT NULL,
  body             TEXT NOT NULL,
  is_opening       BOOLEAN NOT NULL DEFAULT FALSE, -- first message in the thread (R3)
  is_solution      BOOLEAN NOT NULL DEFAULT FALSE, -- confirming reply (R3)
  linked_post_id   INT UNSIGNED NULL,              -- reply links to an approval reply (R9)
  linked_object_id INT UNSIGNED NULL,              -- reply links to a catalogue object (R9)
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_posts_thread (thread_id),
  KEY idx_posts_author (author_id),
  CONSTRAINT fk_posts_thread FOREIGN KEY (thread_id) REFERENCES threads(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_posts_linked_post   FOREIGN KEY (linked_post_id)   REFERENCES posts(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_posts_linked_object FOREIGN KEY (linked_object_id) REFERENCES objects(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Proposals: add or change a catalogue object (R4, R5, R9)
CREATE TABLE IF NOT EXISTS proposals (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id        INT UNSIGNED NOT NULL,
  post_id          INT UNSIGNED NULL,              -- post carrying the proposal (NULL if opening post)
  author_id        INT UNSIGNED NOT NULL,
  type             ENUM('add_entry','edit_field') NOT NULL,
  target_object_id INT UNSIGNED NULL,              -- object being changed (edit_field)
  field            VARCHAR(64)  NULL,              -- field being changed (edit_field)
  new_value        VARCHAR(255) NULL,              -- proposed value (edit_field)
  status           ENUM('pending','approved','rejected','reverted') NOT NULL DEFAULT 'pending',
  approver_id      INT UNSIGNED NULL,              -- expert/admin who approved (R5)
  reason           VARCHAR(255) NULL,              -- rejection or revert reason (R9)
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at      TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_proposals_thread (thread_id),
  KEY idx_proposals_author (author_id),
  KEY idx_proposals_status (status),
  CONSTRAINT fk_proposals_thread   FOREIGN KEY (thread_id) REFERENCES threads(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_proposals_post     FOREIGN KEY (post_id)   REFERENCES posts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_proposals_author   FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_proposals_target   FOREIGN KEY (target_object_id) REFERENCES objects(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_proposals_approver FOREIGN KEY (approver_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT chk_proposals_no_self_approve
    CHECK (approver_id IS NULL OR approver_id <> author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Proposed object payload for add_entry proposals (R4)
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

-- 8. Applied catalogue edits: audit log and revert source (R6, R7, R10)
CREATE TABLE IF NOT EXISTS object_edits (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_id   INT UNSIGNED NOT NULL,
  proposal_id INT UNSIGNED NOT NULL,
  field       VARCHAR(64)  NULL,
  old_value   VARCHAR(255) NULL,
  new_value   VARCHAR(255) NULL,
  applied_by  INT UNSIGNED NOT NULL,               -- the approver who applied the change
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_object_edits_object (object_id),
  KEY idx_object_edits_proposal (proposal_id),
  CONSTRAINT fk_object_edits_object   FOREIGN KEY (object_id)   REFERENCES objects(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_object_edits_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_object_edits_applier  FOREIGN KEY (applied_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Disputes against a previously approved proposal (R6, R7)
CREATE TABLE IF NOT EXISTS disputes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NOT NULL,               -- user filing the dispute
  reason      TEXT NOT NULL,
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  resolver_id INT UNSIGNED NULL,                   -- must differ from author AND original approver
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_disputes_proposal (proposal_id),
  KEY idx_disputes_author (author_id),
  CONSTRAINT fk_disputes_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_disputes_author   FOREIGN KEY (author_id)   REFERENCES users(id),
  CONSTRAINT fk_disputes_resolver FOREIGN KEY (resolver_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT chk_disputes_no_self_resolve
    CHECK (resolver_id IS NULL OR resolver_id <> author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cross-table self-approval guard (R6): a dispute may not be resolved by the
-- user who approved the proposal being disputed. CHECK cannot span tables in
-- MySQL/MariaDB, so this is enforced with triggers.
DELIMITER $$

CREATE TRIGGER trg_disputes_no_original_approver_insert
BEFORE INSERT ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_approver INT UNSIGNED;
  SELECT approver_id INTO v_approver FROM proposals WHERE id = NEW.proposal_id;
  IF v_approver IS NOT NULL AND NEW.resolver_id = v_approver THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'a dispute cannot be resolved by the proposal approver';
  END IF;
END$$

CREATE TRIGGER trg_disputes_no_original_approver_update
BEFORE UPDATE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_approver INT UNSIGNED;
  SELECT approver_id INTO v_approver FROM proposals WHERE id = NEW.proposal_id;
  IF v_approver IS NOT NULL AND NEW.resolver_id = v_approver THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'a dispute cannot be resolved by the proposal approver';
  END IF;
END$$

DELIMITER ;

-- Logic notes (implemented in the application layer):
--
-- Expert promotion (R5): a user becomes an expert when their count of accepted
-- proposals minus the count of their later-reverted contributions reaches a
-- threshold. The negative score is a fast indexed count over object_edits;
-- the positive score counts approved proposals and disputes where their work
-- survived. Both are kept as application queries, not stored columns.
--
-- Revert on dispute approval (R6): approval of a dispute writes a reverse row
-- to object_edits (old_value = reverted value, new_value = last good value) and
-- sets the target proposal's status to 'reverted', so the object_edits log is
-- the single source of truth for both history and demotion scoring.
--
-- Expert demotion (R7): when the net score drops below the threshold, the
-- application demotes the user by updating users.expertise.
