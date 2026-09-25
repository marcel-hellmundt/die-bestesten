-- Migration: "Die Klebrigsten" — Ankündigung neuer Packs geräteübergreifend merken (sticker_pack.announced_at)
-- MANUELL auf der globalen DB ausführen (dev UND prod), nach migrate_stickers.sql.

ALTER TABLE sticker_pack
    ADD COLUMN announced_at DATETIME NULL AFTER created_at;  -- NULL = noch nicht groß angekündigt

-- Bereits vorhandene ungeöffnete Packs nicht nachträglich noch einmal groß ankündigen
UPDATE sticker_pack SET announced_at = NOW() WHERE announced_at IS NULL;
