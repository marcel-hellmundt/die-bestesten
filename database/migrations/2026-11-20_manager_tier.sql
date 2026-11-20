-- Migration: Tier je Manager (Free/Pro)
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Einmal ausführen.
--
-- Rein additiv: eine neue Tabelle — der Code auf main kennt sie nicht und läuft unverändert weiter. Ohne diese
-- Migration ist jeder Manager Free, und ein Admin kann Pro nicht vergeben (POST /manager/:id/tier → 409).
--
-- manager_tier: die Stufe eines Managers, getrennt von den Rollen (Rollen regeln, was jemand verwalten darf; das Tier,
-- welche Features er nutzen darf). Keine Zeile = Free. Welche Features (Entitlements) ein Tier freischaltet, steht im
-- Code (TierTrait::tierConfig()), nicht in der Datenbank.

CREATE TABLE IF NOT EXISTS manager_tier (
    manager_id CHAR(36)    NOT NULL PRIMARY KEY,
    tier       VARCHAR(20) NOT NULL,              -- pro
    granted_by CHAR(36)    NULL,                  -- Admin, der es vergeben hat (kein FK)
    granted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
