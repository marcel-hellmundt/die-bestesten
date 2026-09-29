-- Migration: Sonder-Packs "Geburtstag" + "Weihnachten" für "Die Klebrigsten"
-- Ziel: GLOBALE DB, dev + prod. Idempotent (MODIFY setzt die ENUM-Liste nur neu, bestehende Werte bleiben erhalten).
--
-- sticker_pack.source bekommt zwei neue Werte:
--   birthday  — am Geburtstag (manager.date_of_birth), sonst beim nächsten Login danach; source_key birthday:{Jahr}
--   christmas — wer vom 24. bis 26.12. online ist; source_key christmas:{Jahr}
-- Vergabe in StickerPackTrait::getMyStickerState() (GET /sticker/me), Regeln in stickerConfig().

ALTER TABLE sticker_pack
    MODIFY source ENUM('daily', 'milestone', 'matchday_best', 'admin', 'shop', 'birthday', 'christmas')
        CHARACTER SET utf8mb4 NOT NULL;
