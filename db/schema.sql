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
  object_type     VARCHAR(64) NOT NULL,
  right_ascension VARCHAR(16)  NULL,
  declination     VARCHAR(16)  NULL,
  apparent_mag    DECIMAL(6,3) NULL COMMENT 'apparent magnitude',
  constellation   VARCHAR(16)  NULL,
  distance_ly     DECIMAL(12,3) NULL COMMENT 'distance in light years',
  discovered_by   VARCHAR(128) NULL,
  discovery_year  SMALLINT UNSIGNED NULL,
  notes           TEXT NULL,
  status          ENUM('active','deleted') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catalog (catalog_id),
  KEY idx_type (object_type),
  KEY idx_constellation (constellation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
