-- Startup Street: database initialization (LEVEL 1).
-- Creates the database and the table that tracks which migrations have run.
-- Game tables (players, properties, businesses, ...) arrive in LEVEL 2 as files
-- inside database/migrations/.

CREATE DATABASE IF NOT EXISTS startup_street
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE startup_street;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  filename    VARCHAR(255) NOT NULL UNIQUE,
  executed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
