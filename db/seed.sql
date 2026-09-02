USE astronomical_catalogue;

-- Skeleton seed rows (replace / extend as needed)
INSERT INTO objects (name, catalog_id, object_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year)
VALUES
  ('Sirius',     'HR 2491',  'star',    101.287155, -16.716116, -1.460, 'Canis Major',   8,    NULL,    NULL),
  ('Andromeda',  'M31',      'galaxy',  10.684708,  41.268750,  3.440,  'Andromeda',     2537000, NULL, 964),
  ('Orion',      'M42',      'nebula',  83.822083,  -5.391111,  4.000,  'Orion',          1344,  NULL, 1610);

-- Forum categories for different object types
USE astronomical_forum;

INSERT INTO categories (name, slug, object_type, description)
VALUES
  ('General Discussion', 'general', NULL, 'Off-topic discussions about astronomy and the catalogue'),
  ('Stars', 'stars', 'star', 'Questions and proposals about stars'),
  ('Galaxies', 'galaxies', 'galaxy', 'Questions and proposals about galaxies'),
  ('Nebulae', 'nebulae', 'nebula', 'Questions and proposals about nebulae'),
  ('Planets & Moons', 'planets', 'planet', 'Questions and proposals about planets and moons'),
  ('Unidentified Objects', 'unknown', NULL, 'Help identifying unknown astronomical objects');
