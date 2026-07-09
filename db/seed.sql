USE astronomical_db;

-- Skeleton seed rows (replace / extend as needed)
INSERT INTO objects (name, catalog_id, object_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year)
VALUES
  ('Sirius',     'HR 2491',  'star',    101.287155, -16.716116, -1.460, 'Canis Major',   8,    NULL,    NULL),
  ('Andromeda',  'M31',      'galaxy',  10.684708,  41.268750,  3.440,  'Andromeda',     2537000, NULL, 964),
  ('Orion',      'M42',      'nebula',  83.822083,  -5.391111,  4.000,  'Orion',          1344,  NULL, 1610);
