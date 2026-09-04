-- Liga-spezifische Datenbank Schema
-- Enthält Tabellen für team, transaction, team_rating, team_lineup, player_in_team
-- manager und verwandte Tabellen (manager_role, notification, etc.) sind in global_schema

SET NAMES utf8mb4;
SET character_set_client = utf8mb4;

-- Tabelle: team (1 Team pro Manager pro Saison)
CREATE TABLE IF NOT EXISTS team (
    id              CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id      CHAR(36)     NOT NULL,             -- Referenz auf global_schema.manager.id (kein FK, cross-DB)
    season_id       CHAR(36)     NOT NULL,             -- Referenz auf global_schema.season.id (kein FK, cross-DB)
    team_name       VARCHAR(100) NOT NULL,
    color_primary   VARCHAR(50)  DEFAULT NULL,         -- Logische Referenz auf global_schema.color.name (kein FK, cross-DB)
    color_secondary VARCHAR(50)  DEFAULT NULL,         -- Logische Referenz auf global_schema.color.name (kein FK, cross-DB)
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_team_manager_season (manager_id, season_id),
    UNIQUE KEY uk_team_name_season (team_name, season_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: transaction (Einnahmen/Ausgaben pro Team; aktuelles Budget = SUM(amount))
CREATE TABLE IF NOT EXISTS transaction (
    id           CHAR(36)      NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id      CHAR(36)      NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,            -- positiv = Einnahme, negativ = Ausgabe
    reason       VARCHAR(255)  NOT NULL,            -- z.B. "Spielerkauf: Max Mustermann"
    matchday_id  CHAR(36)      DEFAULT NULL,        -- Referenz auf global_schema.matchday.id (kein FK, cross-DB); NULL wenn nicht spieltagsbezogen
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: team_rating (1 Rating pro Team pro Spieltag)
CREATE TABLE IF NOT EXISTS team_rating (
    id                  CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id             CHAR(36)    NOT NULL,
    matchday_id         CHAR(36)    NOT NULL,            -- Referenz auf global_schema.matchday.id (kein FK, cross-DB)
    points              INT         DEFAULT NULL,
    max_points          INT         DEFAULT NULL,        -- maximal erreichbare Punkte dieses Spieltags
    goals               INT         DEFAULT NULL,
    assists             INT         DEFAULT NULL,
    red_cards           INT         DEFAULT NULL,        -- echte Platzverweise (red_card) der aufgestellten Spieler
    yellow_red_cards    INT         DEFAULT NULL,        -- Gelb-Rote Karten (yellow_red_card) der aufgestellten Spieler
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
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

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
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: offer (Gebote auf Spieler in einer Transferphase)
CREATE TABLE IF NOT EXISTS offer (
    id                  CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_id           CHAR(36)    NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    team_id             CHAR(36)    NOT NULL,             -- bietendes Team
    transferwindow_id   CHAR(36)    NOT NULL,             -- Referenz auf global_schema.transferwindow.id (kein FK, cross-DB)
    offer_value         INT         NOT NULL,
    price_snapshot      INT         DEFAULT NULL,         -- Marktwert zum Zeitpunkt des Gebots (denormalisiert für Performance)
    status              ENUM('pending', 'success', 'lost', 'cancelled') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
    created_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME    DEFAULT NULL,           -- Zeitpunkt der letzten Gebotsänderung (PATCH /offer/:id); NULL = noch nie bearbeitet
    FOREIGN KEY (team_id) REFERENCES team(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: sell (Direktverkauf eines Spielers zu Marktwert)
CREATE TABLE IF NOT EXISTS sell (
    id                CHAR(36)  NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_id         CHAR(36)  NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    team_id           CHAR(36)  NOT NULL,             -- verkaufendes Team
    transferwindow_id CHAR(36)  NOT NULL,             -- Referenz auf global_schema.transferwindow.id (kein FK, cross-DB)
    price             INT       NOT NULL,             -- Marktwert zum Zeitpunkt des Verkaufs
    created_at        DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: player_in_team (Spieler-Zugehörigkeit zu einem Team pro Transferphase)
-- Ein Team kann denselben Spieler mehrfach in derselben Transferphase kaufen/verkaufen (ein
-- Spieltag hat 2–4 Transferfenster, alle mit demselben from_matchday_id bei Rückkauf) — daher
-- KEIN Unique auf (player_id, team_id, from_matchday_id) mehr (führte zu SQLSTATE[23000] beim
-- Rückkauf). Stattdessen erzwingt active_flag (generated column, NULL solange nicht aktiv) über
-- uk_player_team_active: höchstens 1 aktiver Eintrag (to_matchday_id IS NULL) pro Team+Spieler.
CREATE TABLE IF NOT EXISTS player_in_team (
    id               CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id          CHAR(36) NOT NULL,
    player_id        CHAR(36) NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    from_matchday_id CHAR(36) NOT NULL,             -- Transferphase Kauf — Referenz auf global_schema.matchday.id (kein FK, cross-DB)
    to_matchday_id   CHAR(36) NULL DEFAULT NULL,    -- Transferphase Verkauf — NULL = aktuell aktiv
    offer_id         CHAR(36) NULL DEFAULT NULL,    -- Referenz auf das Kaufangebot
    sell_id          CHAR(36) NULL DEFAULT NULL,    -- Referenz auf den Verkauf
    active_flag      CHAR(36) GENERATED ALWAYS AS (IF(to_matchday_id IS NULL, player_id, NULL)) STORED,
    FOREIGN KEY (team_id) REFERENCES team(id),
    FOREIGN KEY (offer_id) REFERENCES offer(id),
    FOREIGN KEY (sell_id) REFERENCES sell(id),
    UNIQUE KEY uk_player_team_active (team_id, active_flag)  -- max. 1 aktiver Eintrag pro Team+Spieler
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: team_award (welches Team hat welchen Award in welcher Saison gewonnen)
-- award-Typen sind in global_schema.award definiert (cross-DB, kein FK auf award_id)
CREATE TABLE IF NOT EXISTS team_award (
    id       CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id  CHAR(36) NOT NULL,
    award_id CHAR(36) NOT NULL,              -- Referenz auf global_schema.award.id (kein FK, cross-DB)
    FOREIGN KEY (team_id) REFERENCES team(id),
    UNIQUE KEY uk_team_award (award_id, team_id)  -- ein Team kann denselben Award nicht zweimal gewinnen
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_watchlist (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    team_id    CHAR(36) NOT NULL,
    player_id  CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id) ON DELETE CASCADE,
    UNIQUE KEY uk_team_player (team_id, player_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: powerranking_pick (Tabellenplatz-Tipp eines Managers für ein Team der Saison —
-- "Kicker-Stecktabelle"; Tippphase bis Anpfiff von Spieltag 1, danach für alle sichtbar/gesperrt)
CREATE TABLE IF NOT EXISTS powerranking_pick (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id  CHAR(36) NOT NULL,             -- Referenz auf global_schema.season.id (kein FK, cross-DB)
    manager_id CHAR(36) NOT NULL,             -- Referenz auf global_schema.manager.id (kein FK, cross-DB)
    team_id    CHAR(36) NOT NULL,             -- getipptes Team
    position   INT      NOT NULL,             -- getippter Tabellenplatz (1 = Meister)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES team(id) ON DELETE CASCADE,
    UNIQUE KEY uk_powerranking_pick     (season_id, manager_id, team_id),
    UNIQUE KEY uk_powerranking_position (season_id, manager_id, position)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- H2H-Turniermodus
CREATE TABLE IF NOT EXISTS h2h_group (
    id         CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id  CHAR(36)     NOT NULL,
    name       VARCHAR(50)  NOT NULL,
    sort_index INT          NOT NULL DEFAULT 0
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS h2h_group_team (
    id       CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    group_id CHAR(36) NOT NULL,
    team_id  CHAR(36) NOT NULL,
    UNIQUE KEY uk_h2h_group_team (group_id, team_id),
    FOREIGN KEY (group_id) REFERENCES h2h_group(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS h2h_match (
    id            CHAR(36)                                                         NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id     CHAR(36)                                                         NOT NULL,
    phase         ENUM('group','quarterfinal','semifinal','final') CHARACTER SET utf8mb4 NOT NULL,
    leg           TINYINT                                                          NOT NULL DEFAULT 1,
    home_team_id  CHAR(36)                                                         NOT NULL,
    away_team_id  CHAR(36)                                                         NOT NULL,
    matchday_id   CHAR(36)                                                         NOT NULL,
    group_id      CHAR(36)                                                         NULL DEFAULT NULL,
    sort_index    INT                                                              NOT NULL DEFAULT 0,
    FOREIGN KEY (group_id) REFERENCES h2h_group(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: h2h_prediction (Tipp eines Managers auf ein H2H-Match — bis Anpfiff der Matchday
-- privat/änderbar, danach für alle Manager sichtbar, siehe H2HPredictionTrait)
CREATE TABLE IF NOT EXISTS h2h_prediction (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    match_id   CHAR(36) NOT NULL,
    manager_id CHAR(36) NOT NULL,             -- Referenz auf global_schema.manager.id (kein FK, cross-DB)
    pick       ENUM('home','draw','away') CHARACTER SET utf8mb4 NOT NULL,
    odds       DECIMAL(6,2) NULL,             -- Pseudo-Quote (H2HTrait::calculateH2HOdds) des Picks,
                                               -- wie im Frontend bei Tippabgabe angezeigt — kann sich
                                               -- bis Anpfiff durch Aufstellungsänderungen noch ändern
    stake      INT NULL,                      -- Einsatz in Lukaten (fiktive Währung, optional, min 1,
                                               -- max aktuelles Budget); NULL = Tipp ohne Einsatz (auch
                                               -- alle vor Einführung dieses Features abgegebenen Tipps).
                                               -- Lukaten-Budget je Manager+Saison wird live berechnet:
                                               -- 100 - SUM(stake) + SUM(stake*odds WHERE result='won'),
                                               -- kein gespeicherter Kontostand (siehe H2HPredictionTrait)
    result     ENUM('open','won','lost') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'open',
                                               -- 'open' bis Spieltagsabschluss, danach von
                                               -- H2HPredictionTrait::evaluateH2HPredictionResults()
                                               -- gesetzt: 'won' wenn pick == tatsächliches Ergebnis
                                               -- (siehe H2HTrait::h2hGoals()), sonst 'lost'
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES h2h_match(id) ON DELETE CASCADE,
    UNIQUE KEY uk_h2h_prediction (match_id, manager_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
