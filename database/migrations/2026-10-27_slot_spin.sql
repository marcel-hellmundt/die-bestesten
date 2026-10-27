-- Migration: Spielhalle — einarmiger Bandit (SlotTrait)
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Einmal ausführen.
--
-- Rein additiv: eine neue Tabelle, die der Code auf main nicht kennt. Ohne sie meldet GET /slot ready=false und
-- POST /slot/spin 409.
--
-- slot_spin: je Dreh eine Zeile (Verlauf + Bilanz auf /spielhalle). Gebucht wird der Dreh zusätzlich im Kontobuch
-- (lukaten_transaction, source 'slot', Schlüssel slot:{id}, Betrag = Gewinn − Einsatz).

CREATE TABLE IF NOT EXISTS slot_spin (
    id         CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36)    NOT NULL,
    stake      TINYINT     NOT NULL,           -- Einsatz in Lukaten (1–3)
    stops      VARCHAR(20) NOT NULL,           -- gewürfelter Stopp je Walze, z.B. '3|17|9' (Walzen in SlotTrait::slotConfig())
    win_key    VARCHAR(20) NULL,               -- Gewinnzeile der Gewinntabelle, NULL = kein Gewinn
    payout     INT         NOT NULL DEFAULT 0, -- ausgezahlte Lukaten (0 ohne Gewinn)
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    KEY idx_slot_spin_manager (manager_id, created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
