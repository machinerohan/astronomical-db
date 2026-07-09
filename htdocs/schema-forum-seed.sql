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

-- Categories
INSERT INTO categories (id, parent_id, name, slug, description, sort_order) VALUES
  (1, NULL, 'General',          'general',           'General astronomy discussion',                        1),
  (2, NULL, 'Help Identifying', 'identifications',    'Identify celestial objects from descriptions/photos',2),
  (3, NULL, 'Stars',            'stars',             'Discussion about stars',                              3),
  (4, 3,    'Stars — Proposals','stars-proposals',   'Propose additions or changes to star entries',        4),
  (5, NULL, 'Nebulae & Clusters','nebulae-clusters',  'Discussion about nebulae and star clusters',          5),
  (6, 5,    'Nebulae — Proposals','nebulae-proposals','Propose additions or changes to nebula/cluster entries',6),
  (7, NULL, 'Galaxies',         'galaxies',           'Discussion about galaxies and quasars',               7),
  (8, 7,    'Galaxies — Proposals','galaxies-proposals','Propose additions or changes to galaxy entries',    8),
  (9, NULL, 'Solar System',     'solar-system',       'Discussion about planets, moons, asteroids, comets',  9),
  (10, 9,   'Solar System — Proposals','solar-proposals','Propose additions or changes to solar system entries',10),
  (11, NULL,'Deep Sky',         'deep-sky',           'Deep sky objects not covered by other categories',   11),
  (12, 11,  'Deep Sky — Proposals','deep-sky-proposals','Propose additions or changes to deep sky entries', 12);

-- Category entry type mappings
INSERT INTO category_entry_types (category_id, entry_type) VALUES
  (3, 'star'),
  (4, 'star'),
  (5, 'nebula'),
  (5, 'emission_nebula'),
  (5, 'reflection_nebula'),
  (5, 'planetary_nebula'),
  (5, 'open_cluster'),
  (5, 'globular_cluster'),
  (6, 'nebula'),
  (6, 'emission_nebula'),
  (6, 'reflection_nebula'),
  (6, 'planetary_nebula'),
  (6, 'open_cluster'),
  (6, 'globular_cluster'),
  (7, 'galaxy'),
  (7, 'quasar'),
  (8, 'galaxy'),
  (8, 'quasar'),
  (9, 'planet'),
  (9, 'dwarf_planet'),
  (9, 'moon'),
  (9, 'asteroid'),
  (9, 'comet'),
  (10, 'planet'),
  (10, 'dwarf_planet'),
  (10, 'moon'),
  (10, 'asteroid'),
  (10, 'comet'),
  (11, 'nebula'),
  (11, 'galaxy'),
  (11, 'cluster'),
  (11, 'supernova_remnant'),
  (12, 'nebula'),
  (12, 'galaxy'),
  (12, 'cluster'),
  (12, 'supernova_remnant');
