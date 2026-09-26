-- Migration: "Die Klebrigsten" — Tauschen unter Managern (sticker_trade, sticker_trade_item, sticker_pull.trade_id)
-- MANUELL auf der globalen DB ausführen (dev UND prod). Idempotent bis auf das ALTER TABLE
-- (schlägt beim zweiten Lauf mit "Duplicate column" fehl — dann ignorieren).

-- Tauschangebot: from_manager bietet to_manager eigene Doppelte gegen dessen Doppelte an
CREATE TABLE IF NOT EXISTS sticker_trade (
    id              CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id       CHAR(36) NOT NULL,
    from_manager_id CHAR(36) NOT NULL,   -- hat das Angebot gemacht
    to_manager_id   CHAR(36) NOT NULL,   -- nimmt an / lehnt ab
    status          ENUM('pending', 'accepted', 'declined', 'cancelled', 'void') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
                                          -- void = hinfällig (ein Sticker ist kein Doppelter mehr)
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at    DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (season_id)       REFERENCES season(id),
    FOREIGN KEY (from_manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (to_manager_id)   REFERENCES manager(id) ON DELETE CASCADE,
    KEY idx_sticker_trade_to (to_manager_id, status),
    KEY idx_sticker_trade_from (from_manager_id, status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Je Sticker eine Zeile; giver_id = wer ihn abgibt (from_manager_id oder to_manager_id)
CREATE TABLE IF NOT EXISTS sticker_trade_item (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    trade_id   CHAR(36) NOT NULL,
    sticker_id CHAR(36) NOT NULL,
    giver_id   CHAR(36) NOT NULL,
    FOREIGN KEY (trade_id)   REFERENCES sticker_trade(id) ON DELETE CASCADE,
    FOREIGN KEY (sticker_id) REFERENCES sticker(id),
    UNIQUE KEY uk_sticker_trade_item (trade_id, sticker_id, giver_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Getauschte Karten wechseln den Besitzer (manager_id), trade_id markiert sie
ALTER TABLE sticker_pull
    ADD COLUMN trade_id CHAR(36) NULL DEFAULT NULL AFTER holo;
