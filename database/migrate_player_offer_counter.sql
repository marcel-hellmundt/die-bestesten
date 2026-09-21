-- Migration Phase 2: Gegenangebote (player_offer.proposed_by + Status 'countered')
-- MANUELL auf JEDER Liga-DB ausführen (dev UND prod), NACH migrate_player_offer.sql, vor dem Deploy der API.
-- Nicht idempotent (zweiter Lauf schlägt bei "Duplicate column" fehl — dann ignorieren).
-- open_key/uk_player_offer_open hängen an der Spalte status und werden deshalb zuerst entfernt und danach neu angelegt.

ALTER TABLE player_offer
    DROP INDEX uk_player_offer_open,
    DROP COLUMN open_key;

ALTER TABLE player_offer
    MODIFY COLUMN status ENUM('pending', 'accepted', 'declined', 'cancelled', 'expired', 'void', 'countered')
        CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
    ADD COLUMN proposed_by ENUM('buyer', 'seller') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'buyer' AFTER seller_team_id;

ALTER TABLE player_offer
    ADD COLUMN open_key CHAR(73) GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(buyer_team_id, '|', player_id), NULL)) STORED,
    ADD UNIQUE KEY uk_player_offer_open (open_key);
