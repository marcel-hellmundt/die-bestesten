-- Liga-spezifische Datenbank Schema
-- Enthält Tabellen für manager, team, transaction, team_rating, team_lineup, player_in_team

CREATE TABLE IF NOT EXISTS manager (
    id            CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    manager_name  VARCHAR(64)  NOT NULL UNIQUE,
    alias         VARCHAR(64)  NULL DEFAULT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    status        ENUM('active', 'blocked', 'deleted') NOT NULL DEFAULT 'active',
    email         VARCHAR(255) NULL UNIQUE,
    date_of_birth DATE         NULL,
    last_activity DATETIME     NULL DEFAULT NULL
);

-- Zusätzliche Rollen pro Manager (additiv; jeder Manager hat implizit die Basisrolle 'manager')
CREATE TABLE IF NOT EXISTS manager_role (
    id         CHAR(36)                        NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    manager_id CHAR(36)                        NOT NULL,
    role       ENUM('maintainer', 'admin')     NOT NULL,
    UNIQUE KEY uk_manager_role (manager_id, role),
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE
);

-- Migration bestehender Rollen (einmalig ausführen, wenn role-Spalte noch existiert):
-- INSERT INTO manager_role (manager_id, role) SELECT id, 'maintainer' FROM manager WHERE role IN ('maintainer', 'admin');
-- INSERT INTO manager_role (manager_id, role) SELECT id, 'admin'      FROM manager WHERE role = 'admin';
-- ALTER TABLE manager DROP COLUMN role;

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

-- Tabelle: team_lineup (Aufstellung pro Team pro Spieltag — alle Kader-Spieler, nominated = eingesetzt)
CREATE TABLE IF NOT EXISTS team_lineup (
    id             CHAR(36)   NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id        CHAR(36)   NOT NULL,
    player_id      CHAR(36)   NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    matchday_id    CHAR(36)   NOT NULL,             -- Referenz auf global_schema.matchday.id (kein FK, cross-DB)
    nominated      TINYINT(1) NOT NULL DEFAULT 0,
    position_index INT        NULL DEFAULT NULL,    -- visuell: Reihenfolge pro Position (links/mitte/rechts)
    FOREIGN KEY (team_id) REFERENCES team(id),
    UNIQUE KEY uk_team_lineup (team_id, player_id, matchday_id)
);

-- Tabelle: offer (Gebote auf Spieler in einer Transferphase)
CREATE TABLE IF NOT EXISTS offer (
    id                  CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_id           CHAR(36)    NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    team_id             CHAR(36)    NOT NULL,             -- bietendes Team
    transferwindow_id   CHAR(36)    NOT NULL,             -- Referenz auf global_schema.transferwindow.id (kein FK, cross-DB)
    offer_value         INT         NOT NULL,
    price_snapshot      INT         DEFAULT NULL,         -- Marktwert zum Zeitpunkt des Gebots (denormalisiert für Performance)
    status              ENUM('pending', 'success', 'lost', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id)
);

-- Tabelle: sell (Direktverkauf eines Spielers zu Marktwert)
CREATE TABLE IF NOT EXISTS sell (
    id                CHAR(36)  NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_id         CHAR(36)  NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    team_id           CHAR(36)  NOT NULL,             -- verkaufendes Team
    transferwindow_id CHAR(36)  NOT NULL,             -- Referenz auf global_schema.transferwindow.id (kein FK, cross-DB)
    price             INT       NOT NULL,             -- Marktwert zum Zeitpunkt des Verkaufs
    created_at        DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id)
);

-- Tabelle: player_in_team (Spieler-Zugehörigkeit zu einem Team pro Transferphase)
-- Applikationsebene stellt sicher: pro Spieler max. 1 aktiver Eintrag (to_matchday_id IS NULL)
CREATE TABLE IF NOT EXISTS player_in_team (
    id               CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id          CHAR(36) NOT NULL,
    player_id        CHAR(36) NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    from_matchday_id CHAR(36) NOT NULL,             -- Transferphase Kauf — Referenz auf global_schema.matchday.id (kein FK, cross-DB)
    to_matchday_id   CHAR(36) NULL DEFAULT NULL,    -- Transferphase Verkauf — NULL = aktuell aktiv
    offer_id         CHAR(36) NULL DEFAULT NULL,    -- Referenz auf das Kaufangebot
    sell_id          CHAR(36) NULL DEFAULT NULL,    -- Referenz auf den Verkauf
    FOREIGN KEY (team_id) REFERENCES team(id),
    FOREIGN KEY (offer_id) REFERENCES offer(id),
    FOREIGN KEY (sell_id) REFERENCES sell(id),
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
