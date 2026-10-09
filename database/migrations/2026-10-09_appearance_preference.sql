-- Migration: Erscheinungs-Einstellungen je Nutzer (eigene Tabelle, getrennt von den Benachrichtigungen)
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Idempotent.
--
-- Rein additiv: eine neue Tabelle, die der Code auf main nicht kennt — für Production ändert sich nichts.
--
-- appearance_preference hält, wie die App für einen Manager aussieht, unabhängig vom Gerät. Bisher: welche Einträge
-- rechts in der Topbar stehen, getrennt für Desktop und Handy (topbar_desktop_* / topbar_mobile_*, siehe
-- AppearanceTrait). Fehlt eine Zeile, gilt der Standard aus dem Code. Ohne diese Migration zeigt die App den Standard
-- und die Schalter unter Einstellungen → Erscheinung speichern nicht.
--
-- Die Topbar-Schalter lagen kurz in notification_preference (nur auf development ausprobiert): vorhandene Zeilen
-- werden übernommen und dort entfernt.

CREATE TABLE IF NOT EXISTS appearance_preference (
    manager_id CHAR(36)    NOT NULL,
    pref_key   VARCHAR(50) NOT NULL,   -- z.B. 'topbar_desktop_karte', 'topbar_mobile_lukaten'
    enabled    BOOL        NOT NULL DEFAULT 1,
    PRIMARY KEY (manager_id, pref_key),
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO appearance_preference (manager_id, pref_key, enabled)
SELECT manager_id, event_type, enabled FROM notification_preference WHERE event_type LIKE 'topbar\_%';

DELETE FROM notification_preference WHERE event_type LIKE 'topbar\_%';
