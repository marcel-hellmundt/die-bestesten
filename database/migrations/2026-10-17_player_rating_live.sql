-- Migration: Noten eintragen live — Änderungszeitpunkt je Rating + wer gerade welchen Verein offen hat
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Einmal ausführen.
--
-- Rein additiv: eine neue Spalte mit Default und eine neue Tabelle — der Code auf main kennt beides nicht und läuft
-- unverändert weiter. Ohne diese Migration funktioniert GET /player_rating/live trotzdem (liefert dann bei jedem
-- Abruf alle Zeilen des Vereins und keine Mitschauenden).
--
-- player_rating.updated_at: wann sich eine Zeile zuletzt geändert hat (auch beim Anlegen) — GET /player_rating/live
-- liefert nur die Zeilen, die sich seit dem letzten Abruf geändert haben. Bestehende Zeilen bekommen den Zeitpunkt
-- der Migration.
--
-- rating_presence: welcher Manager gerade welchen Verein auf /daten/ratings offen hat (letzter Abruf seen_at), für
-- die kleinen Fotos "schaut gerade mit" — eine Zeile je Manager, wird bei jedem Abruf überschrieben.

ALTER TABLE player_rating
    ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER points,
    ADD INDEX idx_player_rating_updated (matchday_id, club_id, updated_at);

CREATE TABLE IF NOT EXISTS rating_presence (
    manager_id  CHAR(36) NOT NULL PRIMARY KEY,
    matchday_id CHAR(36) NOT NULL,
    club_id     CHAR(36) NOT NULL,
    seen_at     DATETIME NOT NULL,
    INDEX idx_rating_presence_club (matchday_id, club_id, seen_at),
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
