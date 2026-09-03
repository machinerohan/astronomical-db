CREATE DATABASE IF NOT EXISTS astronomical_catalogue
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE astronomical_catalogue;

CREATE TABLE IF NOT EXISTS object_images (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_id   INT UNSIGNED NOT NULL,
  proposal_id INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  image_path  VARCHAR(255) NOT NULL,
  caption     VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_object_images_object (object_id),
  KEY idx_object_images_proposal (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
