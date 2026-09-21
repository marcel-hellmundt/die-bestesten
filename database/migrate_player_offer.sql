-- Migration: Direktangebote zwischen Managern (player_offer)
-- MANUELL auf JEDER Liga-DB ausführen (dev UND prod), z.B. usr_ud16_151_4 — vor dem Deploy der API.
-- Idempotent bis auf das ALTER TABLE (schlägt beim zweiten Lauf mit "Duplicate column" fehl — dann ignorieren).

-- Tabelle: player_offer (Direktangebot eines Managers für einen Spieler, der aktuell im Team eines
-- anderen Managers ist — "Hinterzimmerdeal", siehe /player_offer). Anders als offer wird nicht am
-- Ende einer Transferphase ausgewertet, sondern vom Verkäufer innerhalb einer Phase angenommen/abgelehnt
-- und dann sofort vollzogen. Gültigkeit: bis Ende des Transferfensters expires_window_id (laufende Phase,
-- sonst nächste) — Ablauf wird immer live gegen transferwindow.end_date bewertet (kein Cron), 'expired'
-- wird nur opportunistisch nachgetragen. offer_value ist nach dem Anlegen unveränderlich.
CREATE TABLE IF NOT EXISTS player_offer (
    id                CHAR(36)   NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_id         CHAR(36)   NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    buyer_team_id     CHAR(36)   NOT NULL,             -- bietendes Team
    seller_team_id    CHAR(36)   NOT NULL,             -- Team, das den Spieler zum Angebotszeitpunkt hält
    offer_value       INT        NOT NULL,             -- Geldangebot (reserviert das Budget des Bieters, solange pending)
    price_snapshot    INT        NOT NULL,             -- Marktwert (Verkaufsformel) zum Zeitpunkt des Angebots
    status            ENUM('pending', 'accepted', 'declined', 'cancelled', 'expired', 'void') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
                                                       -- void = hinfällig (Spieler anderweitig vergeben/verkauft)
    expires_window_id CHAR(36)   NOT NULL,             -- Referenz auf global_schema.transferwindow.id (kein FK, cross-DB) — Ende dieses Fensters = Ablauf
    settled_window_id CHAR(36)   NULL DEFAULT NULL,    -- Transferfenster, in dem der Deal angenommen wurde (für die Hinterzimmerdeals-Ansicht)
    parent_offer_id   CHAR(36)   NULL DEFAULT NULL,    -- reserviert für Gegenangebote (Phase 2)
    created_at        DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME   NULL DEFAULT NULL,
    responded_at      DATETIME   NULL DEFAULT NULL,
    open_key          CHAR(73)   GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(buyer_team_id, '|', player_id), NULL)) STORED,
    FOREIGN KEY (buyer_team_id) REFERENCES team(id),
    FOREIGN KEY (seller_team_id) REFERENCES team(id),
    FOREIGN KEY (parent_offer_id) REFERENCES player_offer(id),
    UNIQUE KEY uk_player_offer_open (open_key)         -- max. 1 offenes Angebot pro Bieter+Spieler
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: player_offer_player (Spieler als Gegenwert im Angebot — Phase 3, in Phase 1 ungenutzt)
CREATE TABLE IF NOT EXISTS player_offer_player (
    id              CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_offer_id CHAR(36) NOT NULL,
    player_id       CHAR(36) NOT NULL,                 -- Referenz auf global_schema.player.id (kein FK, cross-DB) — Spieler des Bieters
    FOREIGN KEY (player_offer_id) REFERENCES player_offer(id) ON DELETE CASCADE,
    UNIQUE KEY uk_player_offer_player (player_offer_id, player_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE player_in_team
    ADD COLUMN player_offer_id CHAR(36) NULL DEFAULT NULL AFTER sell_id,
    ADD CONSTRAINT fk_pit_player_offer FOREIGN KEY (player_offer_id) REFERENCES player_offer(id);
