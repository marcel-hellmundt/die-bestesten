-- Liga-spezifische Datenbank Schema
-- Enthält Tabellen für manager, team, transaction, team_rating, team_lineup, player_in_team

CREATE TABLE IF NOT EXISTS manager (
    id            CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    manager_name  VARCHAR(64)  NOT NULL UNIQUE,
    alias         VARCHAR(64)  NULL DEFAULT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          ENUM('admin', 'maintainer', 'manager') NOT NULL DEFAULT 'manager',
    status        ENUM('active', 'blocked', 'deleted') NOT NULL DEFAULT 'active',
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

-- Tabelle: team (1 Team pro Manager pro Saison)
CREATE TABLE IF NOT EXISTS team (
    id           CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id   CHAR(36)     NOT NULL,
    season_id    CHAR(36)     NOT NULL,             -- Referenz auf global_schema.season.id (kein FK, cross-DB)
    team_name    VARCHAR(100) NOT NULL,
    color        VARCHAR(7)   DEFAULT NULL,         -- Hex-Farbe, z.B. "#3a86ff"
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES manager(id),
    UNIQUE KEY uk_team_manager_season (manager_id, season_id)
);

-- Tabelle: transaction (Einnahmen/Ausgaben pro Team; aktuelles Budget = SUM(amount))
CREATE TABLE IF NOT EXISTS transaction (
    id           CHAR(36)      NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id      CHAR(36)      NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,            -- positiv = Einnahme, negativ = Ausgabe
    reason       VARCHAR(255)  NOT NULL,            -- z.B. "Spielerkauf: Max Mustermann"
    matchday_id  CHAR(36)      DEFAULT NULL,        -- Referenz auf global_schema.matchday.id (kein FK, cross-DB); NULL wenn nicht spieltagsbezogen
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id)
);

-- Tabelle: team_rating (1 Rating pro Team pro Spieltag)
CREATE TABLE IF NOT EXISTS team_rating (
    id                  CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id             CHAR(36)    NOT NULL,
    matchday_id         CHAR(36)    NOT NULL,            -- Referenz auf global_schema.matchday.id (kein FK, cross-DB)
    points              INT         DEFAULT NULL,
    max_points          INT         DEFAULT NULL,        -- maximal erreichbare Punkte dieses Spieltags
    goals               INT         DEFAULT NULL,
    assists             INT         DEFAULT NULL,
    clean_sheet         TINYINT(1)  DEFAULT NULL,
    sds                 INT         DEFAULT NULL,
    sds_defender        INT         DEFAULT NULL,
    missed_goals        INT         DEFAULT NULL,
    points_goalkeeper   INT         DEFAULT NULL,        -- denormalisiert für Performance (aus player_rating aggregiert)
    points_defender     INT         DEFAULT NULL,
    points_midfielder   INT         DEFAULT NULL,
    points_forward      INT         DEFAULT NULL,
    invalid             TINYINT(1)  NOT NULL DEFAULT 0,  -- 1 = kein Team rechtzeitig aufgestellt
    FOREIGN KEY (team_id) REFERENCES team(id),
    UNIQUE KEY uk_team_rating (team_id, matchday_id)
);

-- CREATE TABLE IF NOT EXISTS team_lineup (...);

-- Tabelle: player_in_team (Spieler-Zugehörigkeit zu einem Team pro Transferphase)
-- Applikationsebene stellt sicher: pro Spieler max. 1 aktiver Eintrag (to_matchday_id IS NULL)
CREATE TABLE IF NOT EXISTS player_in_team (
    id               CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id          CHAR(36) NOT NULL,
    player_id        CHAR(36) NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    from_matchday_id CHAR(36) NOT NULL,             -- Transferphase Kauf — Referenz auf global_schema.matchday.id (kein FK, cross-DB)
    to_matchday_id   CHAR(36) NULL DEFAULT NULL,    -- Transferphase Verkauf — NULL = aktuell aktiv
    -- offer_id CHAR(36) NULL,                      -- ausstehend: Referenz auf Kaufangebot
    -- sell_id  CHAR(36) NULL,                      -- ausstehend: Referenz auf Verkaufsangebot
    FOREIGN KEY (team_id) REFERENCES team(id),
    UNIQUE KEY uk_player_from (player_id, from_matchday_id)  -- kein Doppelkauf in derselben Transferphase
);

-- Tabelle: team_award (welches Team hat welchen Award in welcher Saison gewonnen)
-- award-Typen sind in global_schema.award definiert (cross-DB, kein FK auf award_id)
CREATE TABLE IF NOT EXISTS team_award (
    id       CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id  CHAR(36) NOT NULL,
    award_id CHAR(36) NOT NULL,              -- Referenz auf global_schema.award.id (kein FK, cross-DB)
    FOREIGN KEY (team_id) REFERENCES team(id),
    UNIQUE KEY uk_team_award (award_id, team_id)  -- ein Team kann denselben Award nicht zweimal gewinnen
);
