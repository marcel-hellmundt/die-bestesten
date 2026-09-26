-- Migration: "Die Klebrigsten"-Shop — gekaufte Packs (sticker_pack.source 'shop' + Vereins-Pack/Neu-Garantie)
-- MANUELL auf der globalen DB ausführen (dev UND prod). Nicht idempotent (ADD COLUMN schlägt beim zweiten Lauf
-- mit "Duplicate column" fehl — dann ignorieren). Gehört zu migrate_sticker_shop.sql (Liga-DBs).

ALTER TABLE sticker_pack
    MODIFY COLUMN source ENUM('daily', 'milestone', 'matchday_best', 'admin', 'shop') CHARACTER SET utf8mb4 NOT NULL,
    ADD COLUMN club_id        CHAR(36)         NULL DEFAULT NULL AFTER league_id,  -- Vereins-Pack: nur Sticker dieses Vereins
    ADD COLUMN guaranteed_new TINYINT UNSIGNED NULL DEFAULT NULL AFTER size;       -- so viele Karten garantiert neu (NULL = Regel je source)
