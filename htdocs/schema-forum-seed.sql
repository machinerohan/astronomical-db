-- AstroForum — seed data
-- Run after htdocs/schema-forum.sql

USE astronomical_db;

-- Users
INSERT INTO users (username, password, role, expertise) VALUES
  ('admin', '$2y$12$hlpnBBokuLxwFOWxBfuNSeNik47E8zl.b38o9vz/IqR8t3mrb5mKG', 'admin', 'verified'),
  ('alice', '$2y$12$bWlmXFlRgAKP8L/c.SEU0OtkN8fczLuOgK3UhipjE4HVEQoGQXcSu', 'member', 'normal');

-- Admin verification note
INSERT INTO user_verifications (user_id, verified_by_id, note) VALUES (1, 1, 'Default admin account');

-- Admin action
INSERT INTO admin_actions (admin_id, action, target_type, target_id) VALUES (1, 'create_user', 'user', 1);
INSERT INTO admin_actions (admin_id, action, target_type, target_id) VALUES (1, 'verify_user', 'user', 1);
INSERT INTO admin_actions (admin_id, action, target_type, target_id) VALUES (1, 'create_user', 'user', 2);

-- Categories (flat — no parent-child hierarchy)
INSERT INTO categories (id, parent_id, name, slug, description, is_proposal, sort_order) VALUES
  (1, NULL, 'General',              'general',           'General astronomy discussion',                        FALSE, 1),
  (2, NULL, 'Help Identifying',     'identifications',    'Identify celestial objects from descriptions/photos', FALSE, 2),
  (3, NULL, 'Stars',                'stars',             'Discussion about stars',                               FALSE, 3),
  (4, NULL, 'Nebulae & Clusters',   'nebulae-clusters',  'Discussion about nebulae and star clusters',           FALSE, 4),
  (5, NULL, 'Galaxies',             'galaxies',           'Discussion about galaxies and quasars',                FALSE, 5),
  (6, NULL, 'Solar System',         'solar-system',       'Discussion about planets, moons, asteroids, comets',   FALSE, 6),
  (7, NULL, 'Deep Sky',             'deep-sky',           'Deep sky objects not covered by other categories',    FALSE, 7),
  (8, NULL, 'Proposals',            'proposals',          'Propose additions, edits, or removals of catalogue entries', TRUE, 8);

-- Category entry type mappings (discussion categories only — Proposals shows all types via fallback)
INSERT INTO category_entry_types (category_id, entry_type) VALUES
  (3, 'star'),
  (4, 'nebula'),
  (4, 'emission_nebula'),
  (4, 'reflection_nebula'),
  (4, 'planetary_nebula'),
  (4, 'open_cluster'),
  (4, 'globular_cluster'),
  (5, 'galaxy'),
  (5, 'quasar'),
  (6, 'planet'),
  (6, 'dwarf_planet'),
  (6, 'moon'),
  (6, 'asteroid'),
  (6, 'comet'),
  (7, 'nebula'),
  (7, 'galaxy'),
  (7, 'cluster'),
  (7, 'supernova_remnant');
