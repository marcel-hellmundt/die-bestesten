-- Migration: Spieltags-Zusammenfassung (Einblendung nach dem Spieltagsabschluss)
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Idempotent (CREATE TABLE IF NOT EXISTS).
--
-- Rein additiv: eine neue Tabelle, die der Code auf main nicht kennt — für Production ändert sich nichts.
--
-- Beim Abschluss eines Spieltags (PATCH /matchday/:id completed=true) hält die API je Manager und Liga fest, was er
-- an dem Spieltag geholt hat (MatchdaySummaryTrait::createMatchdaySummaries()): Punkte, Platz, Torschützen,
-- Einnahmen, Strafe, H2H-Ergebnis und darunter Lukaten für Einträge, Tipps, Packs, Achievements. Die Webapp blendet
-- die noch nicht gesehenen der letzten 5 Tage einmal groß ein (GET /matchday_summary) und markiert sie als gesehen.
-- Ohne diese Migration schreibt der Abschluss nichts und es wird nichts eingeblendet; die Vorschau in der Verwaltung
-- (/verwaltung/ui-tests) rechnet live und funktioniert auch ohne die Tabelle.

CREATE TABLE IF NOT EXISTS matchday_summary (
    id              CHAR(36)   NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id      CHAR(36)   NOT NULL,
    league_id       CHAR(36)   NOT NULL,  -- Liga, in der der Spieltag abgeschlossen wurde
    matchday_id     CHAR(36)   NOT NULL,  -- matchday.id (kein FK)
    notification_id CHAR(36)   NULL,      -- Benachrichtigung "Spieltag N abgeschlossen" (kein FK) — von dort wieder zu öffnen
    payload         MEDIUMTEXT NOT NULL,  -- Inhalt als JSON, so wie er beim Abschluss galt
    created_at      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seen_at         DATETIME   NULL DEFAULT NULL,  -- NULL = noch nicht eingeblendet
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (league_id)  REFERENCES league(id) ON DELETE CASCADE,
    UNIQUE KEY uk_matchday_summary (manager_id, league_id, matchday_id),
    KEY idx_matchday_summary_matchday (matchday_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
