-- Liga-spezifische Datenbank Schema
-- Enthält Tabellen für manager, team, team_rating, team_lineup, player_in_team

CREATE TABLE IF NOT EXISTS manager (
    id            CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    manager_name  VARCHAR(64)  NOT NULL UNIQUE,
    alias         VARCHAR(64)  NULL DEFAULT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          ENUM('admin', 'maintainer', 'manager') NOT NULL DEFAULT 'manager',
    status        ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    deleted       TINYINT(1)   NOT NULL DEFAULT 0,
    email         VARCHAR(255) NULL UNIQUE,
    date_of_birth DATE         NULL
);

CREATE TABLE IF NOT EXISTS password_reset_token (
    id         CHAR(36)    NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    manager_id CHAR(36)    NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME    NOT NULL,
    used       TINYINT(1)  NOT NULL DEFAULT 0,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES manager(id)
);

-- CREATE TABLE IF NOT EXISTS team (...);
-- CREATE TABLE IF NOT EXISTS team_rating (...);
-- CREATE TABLE IF NOT EXISTS team_lineup (...);
-- CREATE TABLE IF NOT EXISTS player_in_team (...);
