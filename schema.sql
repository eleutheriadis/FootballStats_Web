-- ΕΠΟ Stats - MySQL Schema
-- Τρέξε: mysql -u root -p epo_stats < schema.sql

CREATE DATABASE IF NOT EXISTS epo_stats
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE epo_stats;

CREATE TABLE teams (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    city       VARCHAR(100) NOT NULL,
    logo_path  VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE players (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    position   ENUM('GK','DEF','MID','FWD') NOT NULL,
    team_id    INT UNSIGNED NOT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE championships (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    season     VARCHAR(20)  NOT NULL,
    status     ENUM('draft','active','finished') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_champ (name, season)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE championship_teams (
    championship_id INT UNSIGNED NOT NULL,
    team_id         INT UNSIGNED NOT NULL,
    PRIMARY KEY (championship_id, team_id),
    FOREIGN KEY (championship_id) REFERENCES championships(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id)         REFERENCES teams(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matchdays (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    championship_id   INT UNSIGNED NOT NULL,
    number            TINYINT UNSIGNED NOT NULL,
    FOREIGN KEY (championship_id) REFERENCES championships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matches (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    matchday_id  INT UNSIGNED NOT NULL,
    home_team_id INT UNSIGNED NOT NULL,
    away_team_id INT UNSIGNED NOT NULL,
    status       ENUM('scheduled','live','finished') NOT NULL DEFAULT 'scheduled',
    home_score   TINYINT UNSIGNED DEFAULT 0,
    away_score   TINYINT UNSIGNED DEFAULT 0,
    played_at    DATETIME DEFAULT NULL,
    FOREIGN KEY (matchday_id)  REFERENCES matchdays(id) ON DELETE CASCADE,
    FOREIGN KEY (home_team_id) REFERENCES teams(id),
    FOREIGN KEY (away_team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
