-- Migration: "Die Klebrigsten" — feste Pack-Arten im Shop (normal/big/club/special) + Holo-Garantie
-- MANUELL auf der globalen DB ausführen (dev UND prod). Nicht idempotent (ADD COLUMN schlägt beim zweiten Lauf
-- mit "Duplicate column" fehl — dann ignorieren). Setzt migrate_sticker_shop_pack.sql voraus.
-- Ohne diese Migration: Shop-Käufe schlagen fehl (Packs werden mit pack_kind/holo_min angelegt), alles andere läuft.

ALTER TABLE sticker_pack
    ADD COLUMN pack_kind VARCHAR(20)      NULL DEFAULT NULL AFTER source,          -- Shop: normal | big | club | special (Design + Inhalt); NULL = aus source
    ADD COLUMN holo_min  TINYINT UNSIGNED NULL DEFAULT NULL AFTER guaranteed_new;  -- so viele Karten mindestens Holo (Special-Pack: 1)
