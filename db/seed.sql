USE astronomical_db;

INSERT INTO objects (name, catalog_id, entry_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year)
VALUES
  ('Sirius',     'HR 2491',  'star',    '06:45:08.9', '-16:42:58', -1.460, 'Canis Major',   8,    NULL,    NULL),
  ('Andromeda',  'M31',      'galaxy',  '00:42:44.3', '+41:16:09',  3.440, 'Andromeda',     2537000, NULL, 964),
  ('Orion',      'M42',      'nebula',  '05:35:17.3', '-05:23:28',  4.000, 'Orion',          1344,  NULL, 1610);
