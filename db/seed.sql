-- Sample data. Run after db/schema.sql (catalogue) and db/schema-forum.sql (forum).
-- The first administrator is created through web/setup.php, not seeded here.

-- Catalogue sample rows (catalogue_db)
USE catalogue_db;

INSERT INTO objects (name, catalog_id, object_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year)
VALUES
  ('Sirius',     'HR 2491',  'star',    101.287155, -16.716116, -1.460, 'Canis Major',   8,       NULL, NULL),
  ('Andromeda',  'M31',      'galaxy',  10.684708,   41.268750,  3.440, 'Andromeda',     2537000, NULL, 964),
  ('Orion',      'M42',      'nebula',  83.822083,   -5.391111,  4.000, 'Orion',         1344,    NULL, 1610)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Forum categories (astronomical_db). One subforum per object type (spec R9).
USE astronomical_db;

INSERT INTO categories (name, slug, object_type, description)
VALUES
  ('General discussion', 'general', NULL,     'Anything about the sky, observing, or this forum.'),
  ('Stars',              'stars',   'star',   'Identify and propose changes to stars.'),
  ('Galaxies',           'galaxies','galaxy', 'Identify and propose changes to galaxies.'),
  ('Nebulae',            'nebulae', 'nebula', 'Identify and propose changes to nebulae.'),
  ('Planets',            'planets', 'planet', 'Identify and propose changes to planets.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
