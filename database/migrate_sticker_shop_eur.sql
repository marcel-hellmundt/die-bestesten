-- Migration: "Die Klebrigsten"-Shop — Euro-Käufe per PayPal.me (sticker_eur_purchase + sticker_pack.eur_purchase_id)
-- MANUELL auf der globalen DB ausführen (dev UND prod). Nicht idempotent beim ALTER TABLE
-- ("Duplicate column" beim zweiten Lauf — dann ignorieren). Setzt migrate_sticker_shop_pack.sql voraus.

-- Euro-Kauf: Packs gibt es sofort, bezahlt wird per PayPal.me (kein automatischer Zahlungsnachweis) —
-- ein Admin bestätigt die Zahlung (paid) oder storniert den Kauf (cancelled → Packs + Karten werden gelöscht).
-- Solange pending, sind Karten aus diesen Packs nicht tauschbar (sicher zurücknehmbar).
CREATE TABLE IF NOT EXISTS sticker_eur_purchase (
    id           CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id   CHAR(36)    NOT NULL,
    season_id    CHAR(36)    NOT NULL,
    offer_key    VARCHAR(30) NOT NULL,                  -- Angebot (StickerShopTrait::stickerShopEurOffers())
    amount_cents INT         NOT NULL,                  -- Preis in Cent (Snapshot)
    code         VARCHAR(12) NOT NULL,                  -- Kauf-Code für den PayPal-Verwendungszweck, z.B. DK-4F2A
    status       ENUM('pending', 'paid', 'cancelled') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
    created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at   DATETIME    NULL DEFAULT NULL,         -- bestätigt bzw. storniert am
    handled_by   CHAR(36)    NULL DEFAULT NULL,         -- Admin, der bestätigt/storniert hat
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (season_id)  REFERENCES season(id),
    UNIQUE KEY uk_sticker_eur_purchase_code (code),
    KEY idx_sticker_eur_purchase_status (status),
    KEY idx_sticker_eur_purchase_manager (manager_id, season_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Packs eines Euro-Kaufs (ein Kauf kann mehrere Packs enthalten)
ALTER TABLE sticker_pack
    ADD COLUMN eur_purchase_id CHAR(36) NULL DEFAULT NULL AFTER club_id,
    ADD KEY idx_sticker_pack_eur (eur_purchase_id);
