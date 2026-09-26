-- Migration: "Die Klebrigsten"-Shop — Lukaten gegen Sticker-Packs (sticker_shop_purchase)
-- MANUELL auf JEDER Liga-DB ausführen (dev UND prod). Idempotent.
-- Ohne die Tabelle rechnet die API weiter wie bisher (Shop-Ausgaben = 0).

CREATE TABLE IF NOT EXISTS sticker_shop_purchase (
    id         CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36)    NOT NULL,             -- Referenz auf global_schema.manager.id (kein FK, cross-DB)
    season_id  CHAR(36)    NOT NULL,             -- Referenz auf global_schema.season.id (kein FK, cross-DB)
    offer_key  VARCHAR(30) NOT NULL,             -- gekauftes Angebot (Shop-Konfiguration)
    price      INT         NOT NULL,             -- bezahlte Lukaten (Snapshot)
    pack_id    CHAR(36)    NULL DEFAULT NULL,    -- Referenz auf global_schema.sticker_pack.id (kein FK, cross-DB)
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sticker_shop_purchase (season_id, manager_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
