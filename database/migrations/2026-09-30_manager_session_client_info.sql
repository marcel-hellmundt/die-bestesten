-- Migration: Geräte-/Theme-Infos je Sitzung (Auswertung "Geräte & Erscheinung" auf /daten/nutzung)
-- Ziel: GLOBALE DB, dev + prod. Nicht idempotent (ADD COLUMN) — nur einmal ausführen.
-- Ohne diese Migration läuft der Heartbeat unverändert weiter (die Zusatzfelder werden dann
-- stillschweigend nicht gespeichert), GET /session/devices liefert Geräte ohne Zusatzfelder.
--
-- Werte kommen aus dem Header X-Client-Info (Frontend: core/client-info.service.ts), gesetzt in
-- SessionTrait::storeSessionClientInfo() — letzter Stand der Session zählt.

ALTER TABLE manager_session
    ADD COLUMN theme_pref   VARCHAR(6)  NULL DEFAULT NULL AFTER browser,      -- Wahl: 'light' | 'dark' | 'system'
    ADD COLUMN theme        VARCHAR(5)  NULL DEFAULT NULL AFTER theme_pref,   -- angezeigt: 'light' | 'dark'
    ADD COLUMN system_theme VARCHAR(5)  NULL DEFAULT NULL AFTER theme,        -- Systemeinstellung des Geräts (prefers-color-scheme)
    ADD COLUMN standalone   TINYINT(1)  NULL DEFAULT NULL AFTER system_theme, -- 1 = als App installiert (Home-Bildschirm)
    ADD COLUMN os_version   VARCHAR(12) NULL DEFAULT NULL AFTER standalone,   -- z.B. '17.5' (iOS), '14' (Android), '11' (Windows)
    ADD COLUMN device_model VARCHAR(40) NULL DEFAULT NULL AFTER os_version,   -- nur Android per Client Hints, z.B. 'Pixel 8'
    ADD COLUMN screen       VARCHAR(20) NULL DEFAULT NULL AFTER device_model; -- 'kurz×lang@Pixeldichte', z.B. '375x812@3'
