-- Migration: Länderpunkte auf der Karte (/karte) — welche Länder ein Manager als besucht markiert hat.
-- Ziel: GLOBALE DB, dev + prod. Idempotent (CREATE IF NOT EXISTS, INSERT IGNORE).
-- Ohne diese Migration: GET /manager_country liefert [], Eintragen schlägt fehl; der Rest der Karte läuft.

CREATE TABLE IF NOT EXISTS manager_country (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36) NOT NULL,
    country_id CHAR(2)  NOT NULL,  -- Referenz auf country.id (gleiche DB)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES country(id),
    UNIQUE KEY uk_manager_country (manager_id, country_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Alle, die schon mindestens 1 Stadion als besucht markiert haben, bekommen Deutschland gutgeschrieben
-- (bisher gab es nur deutsche Stadien auf der Karte).
INSERT IGNORE INTO manager_country (id, manager_id, country_id)
SELECT UUID(), ms.manager_id, c.id
FROM (SELECT DISTINCT manager_id FROM manager_stadium) ms
JOIN country c ON LOWER(c.id) = 'de';
