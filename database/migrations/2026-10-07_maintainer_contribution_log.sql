-- Migration: Verlauf der Einträge bei den Spieltagsdaten (wem ein Eintrag gehört)
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Idempotent (CREATE TABLE IF NOT EXISTS).
-- Konzept: docs/lukaten-economy-concept.md, Abschnitt "Einträge"
--
-- Rein additiv: eine neue Tabelle, die der Code auf main nicht kennt — für Production ändert sich nichts.
--
-- Ein Eintrag (maintainer_contribution) gehört dem Manager, der den gültigen Wert eingetragen hat: Wer einen Wert
-- korrigiert, übernimmt den Eintrag (PlayerRatingTrait::assignContribution()). Dieser Verlauf hält fest, wer
-- welchen Wert wann gesetzt hat — damit bleibt ein Eintrag bei dem, der den Wert zuerst so eingetragen hat, wenn
-- jemand ihn ändert und wieder zurückstellt.
-- Ohne diese Migration gilt schlicht: Der Eintrag gehört dem, der den Wert zuletzt geändert hat.

CREATE TABLE IF NOT EXISTS maintainer_contribution_log (
    id                CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_rating_id  CHAR(36)    NOT NULL,  -- player_rating.id (kein FK, wie maintainer_contribution)
    contribution_type ENUM('participation', 'stats', 'note') CHARACTER SET utf8mb4 NOT NULL,
    manager_id        CHAR(36)    NOT NULL,  -- wer den Wert gesetzt hat
    value             VARCHAR(40) NOT NULL,  -- der gesetzte Wert: Einsatz ('starting'), Note ('3.5'), Statistik ('2|0|0|1|0|0' = Tore|Vorlagen|Weiße Weste|SdS|Rot|Gelb-Rot)
    created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    KEY idx_contribution_log (player_rating_id, contribution_type, value, created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
