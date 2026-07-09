-- Astronomical Objects Database — schema skeleton
-- MySQL / MariaDB compatible

CREATE DATABASE IF NOT EXISTS astronomical_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE astronomical_db;

CREATE TABLE IF NOT EXISTS objects (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(255) NOT NULL,
  catalog_id      VARCHAR(64)  NULL,
  object_type     ENUM('star','galaxy','nebula','planet','moon','comet','asteroid','other') NOT NULL DEFAULT 'other',
  right_ascension DECIMAL(9,6) NULL COMMENT 'degrees, 0..360',
  declination     DECIMAL(9,6) NULL COMMENT 'degrees, -90..90',
  apparent_mag    DECIMAL(7,3) NULL COMMENT 'apparent magnitude',
  constellation   VARCHAR(64)  NULL,
  distance_ly     BIGINT UNSIGNED NULL COMMENT 'distance in light years',
  discovered_by   VARCHAR(255) NULL,
  discovery_year  SMALLINT NULL,
  notes           TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catalog (catalog_id),
  KEY idx_type (object_type),
  KEY idx_constellation (constellation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
