-- Migration: Streak-Pack ("Die Klebrigsten") — neue Pack-Quelle
-- Ziel: GLOBALE DB, dev + prod. Idempotent (MODIFY) — kann mehrfach ausgeführt werden.
--
--   streak — 7 Tage in Folge online (streak_days), zusätzlich zum Tages-Pack; 3 Sticker (streak_pack_size)
-- Regeln in StickerPackTrait::stickerConfig(), Vergabe in grantStreakStickerPack() (GET /sticker/me); source_key streak:{Datum}.
-- Ohne diese Migration wird kein Streak-Pack vergeben.

ALTER TABLE sticker_pack
    MODIFY source ENUM('daily', 'milestone', 'matchday_best', 'admin', 'shop', 'birthday', 'christmas', 'streak')
        CHARACTER SET utf8mb4 NOT NULL;
