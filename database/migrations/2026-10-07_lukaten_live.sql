-- Migration: Lukaten als einziges Zahlungsmittel — Start im laufenden Betrieb
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Nicht idempotent (DROP COLUMN) — nur einmal ausführen.
-- Konzept: docs/lukaten-economy-concept.md
--
-- Räumt die Reste des verworfenen Modells auf (Lukaten-Modus je Saison, Admin-Vorschau): das Kontobuch
-- lukaten_transaction gilt ab jetzt für alle, einen Modus und eine Vorschau gibt es nicht mehr.
--   - Buchungen der Admin-Vorschau (preview = 1) löschen, Spalte preview entfernen,
--     Eindeutigkeit jetzt (manager_id, source_key)
--   - season.lukaten_mode entfernen
-- Der Code auf main nutzt weder die Tabelle noch die Spalte (season wird dort per "SELECT *" bzw. mit benannten
-- Spalten gelesen) — für Production ändert sich durch diese Migration nichts.
--
-- Reihenfolge: zuerst diese Migration, dann den neuen Code ausliefern. Der neue Code läuft auch ohne sie, zählt
-- die Vorschau-Buchungen dann aber wie echte.
--
-- Vorher prüfen — erwartet werden nur Zeilen mit preview = 1 (aus der Vorschau):
--   SELECT preview, source, COUNT(*), SUM(amount) FROM lukaten_transaction GROUP BY preview, source;

DELETE FROM lukaten_transaction WHERE preview = 1;

ALTER TABLE lukaten_transaction
    DROP INDEX uk_lukaten_transaction,
    DROP COLUMN preview,
    ADD UNIQUE KEY uk_lukaten_transaction (manager_id, source_key);

ALTER TABLE season
    DROP COLUMN lukaten_mode;
