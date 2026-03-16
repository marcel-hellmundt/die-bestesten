-- Liga-spezifische Datenbank Schema
-- Enthält Tabellen für manager, team, team_rating, team_lineup, player_in_team

CREATE TABLE IF NOT EXISTS manager (
    manager_id   CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    manager_name VARCHAR(64)  NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    role         ENUM('admin', 'manager') NOT NULL DEFAULT 'manager',
    status       ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    has_photo    BOOLEAN      NOT NULL DEFAULT FALSE
);

-- CREATE TABLE IF NOT EXISTS team (...);
-- CREATE TABLE IF NOT EXISTS team_rating (...);
-- CREATE TABLE IF NOT EXISTS team_lineup (...);
-- CREATE TABLE IF NOT EXISTS player_in_team (...);
