-- Migration: Lukaten-Konto — neuer Lukaten-Modus ab der nächsten Saison, Stufe 1
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Nicht idempotent (ADD COLUMN) — nur einmal ausführen.
-- Konzept: docs/lukaten-economy-concept.md
--
-- Rein additiv: eine neue Spalte mit Default und eine neue Tabelle. Der Code auf main liest season nur über
-- "SELECT *" (liefert dann ein zusätzliches Feld, das die Webapp ignoriert) bzw. mit benannten Spalten und legt
-- Saisons mit Spaltenliste an — für Production ändert sich nichts.
--
-- season.lukaten_mode: classic = bisheriger Rechenweg (100 Lukaten je Liga und Saison, live aus h2h_prediction),
--   account = Lukaten-Konto je Manager (lukaten_transaction). Alle bestehenden Saisons bleiben classic; gesetzt
--   wird account erst beim Anlegen der neuen Saison.
-- lukaten_transaction: das Kontobuch — eine Zeile je Bewegung, Kontostand = Summe (LukatenAccountTrait).
--   preview = 1: Buchung aus der Admin-Vorschau (Header X-Lukaten-Preview, nur wo LUKATEN_MODE_SWITCH=true im
--   .env der API steht) — vom echten Konto getrennt, jederzeit löschbar (DELETE /lukaten/preview).
-- Ohne diese Migration ist jede Saison classic, der Shop arbeitet unverändert.

ALTER TABLE season
    ADD COLUMN lukaten_mode ENUM('classic', 'account') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'classic';

CREATE TABLE IF NOT EXISTS lukaten_transaction (
    id         CHAR(36)      NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36)      NOT NULL,
    preview    TINYINT(1)    NOT NULL DEFAULT 0,  -- 1 = Buchung aus der Admin-Vorschau, zählt nicht zum echten Konto
    amount     DECIMAL(10,2) NOT NULL,      -- + Gutschrift, − Ausgabe
    source     VARCHAR(20)   NOT NULL,      -- season_bonus | pack (später: entries, eur, stake, payout)
    source_key VARCHAR(120)  NOT NULL,      -- je Manager eindeutig → idempotent: 'season:{season_id}', 'pack:{pack_id}', Vorschau 'preview-pack:{offer_key}:{uuid}'
    season_id  CHAR(36)      NULL,          -- Saison der Bewegung (nur zur Auswertung — das Konto ist saisonübergreifend)
    pack_id    CHAR(36)      NULL,          -- gekauftes Pack (source = pack; in der Vorschau NULL — es entsteht kein Pack)
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (season_id)  REFERENCES season(id),
    UNIQUE KEY uk_lukaten_transaction (manager_id, preview, source_key),
    KEY idx_lukaten_transaction_source (source, season_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
