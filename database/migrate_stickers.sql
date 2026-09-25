-- Migration: "Die Klebrigsten" (Sticker-Album) — Feature-Schalter pro Liga + Album/Packs/Züge
-- MANUELL auf der globalen DB ausführen (dev UND prod), VOR dem Deploy der API.

ALTER TABLE league
    ADD COLUMN sticker_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER deal_system_enabled;

-- Eingefrorenes Album einer Saison (POST /sticker/album/sync, nur ergänzend — nie gelöscht)
CREATE TABLE IF NOT EXISTS sticker (
    id          CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id   CHAR(36)    NOT NULL,
    sticker_key VARCHAR(50) NOT NULL,  -- player_id bzw. '{club_id}-logo' / '{club_id}-stadium' (= ID im Frontend)
    kind        ENUM('player', 'logo', 'stadium') CHARACTER SET utf8mb4 NOT NULL,
    club_id     CHAR(36)    NOT NULL,  -- Verein am Stichtag 1.9.
    player_id   CHAR(36)    NULL,      -- nur kind = 'player'
    position    ENUM('GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD') CHARACTER SET utf8mb4 NULL,
    price       INT         NOT NULL,  -- Gewichtungs-Marktwert beim Einfrieren (bestimmt Seltenheit)
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES season(id),
    FOREIGN KEY (club_id)   REFERENCES club(id),
    FOREIGN KEY (player_id) REFERENCES player(id),
    UNIQUE KEY uk_sticker (season_id, sticker_key)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Vergebene Packs; source_key macht die Vergabe idempotent (je Manager max. 1 Pack pro Ereignis)
CREATE TABLE IF NOT EXISTS sticker_pack (
    id         CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36)     NOT NULL,
    season_id  CHAR(36)     NOT NULL,
    source     ENUM('daily', 'milestone', 'matchday_best', 'admin') CHARACTER SET utf8mb4 NOT NULL,
    source_key VARCHAR(120) NOT NULL,  -- z.B. 'daily:2026-09-25', 'milestone:{team_id}:200', 'matchday_best:{team_id}:{matchday_id}'
    league_id  CHAR(36)     NULL,      -- Liga des Ereignisses (Meilenstein/Spieltagsbester)
    size       TINYINT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at  DATETIME     NULL,      -- NULL = ungeöffnet
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (season_id)  REFERENCES season(id),
    FOREIGN KEY (league_id)  REFERENCES league(id),
    UNIQUE KEY uk_sticker_pack (manager_id, source_key),
    KEY idx_sticker_pack_open (manager_id, opened_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Gezogene Karten (beim Öffnen eines Packs serverseitig gewürfelt); Sammlung = Summe der Züge
CREATE TABLE IF NOT EXISTS sticker_pull (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    pack_id    CHAR(36) NOT NULL,
    manager_id CHAR(36) NOT NULL,
    sticker_id CHAR(36) NOT NULL,
    slot       TINYINT UNSIGNED NOT NULL,  -- Reihenfolge im Pack
    holo       ENUM('silver', 'gold') CHARACTER SET utf8mb4 NULL,  -- NULL = normale Karte
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pack_id)    REFERENCES sticker_pack(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (sticker_id) REFERENCES sticker(id),
    KEY idx_sticker_pull_manager (manager_id, sticker_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
