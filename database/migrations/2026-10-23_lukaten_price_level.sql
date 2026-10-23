-- Migration: Preisstufen der Sticker-Packs nach der Menge der Lukaten im Umlauf
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Einmal ausführen.
--
-- Rein additiv: eine neue Tabelle, die der Code auf main nicht kennt. Ohne sie rechnet der neue Code die Stufe bei
-- jedem Abruf direkt aus dem 14-Tage-Mittel (ohne "höchstens eine Stufe pro Woche" und ohne Abstand beim Absenken).
--
-- lukaten_price_level: je Woche (ab Montag) die geltende Preisstufe der Lukaten-Packs. Angelegt beim ersten Abruf
-- der Woche aus dem Mittel der Lukaten im Umlauf über die 14 Tage davor (LukatenAccountTrait::getLukatenPriceLevel());
-- danach fest, damit sich die Preise unter der Woche nicht ändern.

CREATE TABLE IF NOT EXISTS lukaten_price_level (
    week_start      DATE          NOT NULL PRIMARY KEY,  -- Montag der Woche
    level           TINYINT       NOT NULL,              -- Index in lukatenAccountConfig()['price_levels'], 0 = Grundpreise
    factor          DECIMAL(4,2)  NOT NULL,              -- Faktor auf die Grundpreise, wie er in dieser Woche galt
    avg_circulation DECIMAL(12,2) NULL,                  -- Mittel der Lukaten im Umlauf über die 14 Tage vor week_start
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
