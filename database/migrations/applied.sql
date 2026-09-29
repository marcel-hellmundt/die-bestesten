-- ============================================================================================
-- ARCHIV: bereits ausgeführte Migrationen (dev + prod) — NICHT erneut ausführen.
-- Der aktuelle Stand steht vollständig in database/global_schema.sql bzw. database/league_schema.sql.
-- Jeder Abschnitt trägt den ursprünglichen Dateinamen (Verweise wie "Migration: migrate_x.sql" in
-- Code/Doku meinen den gleichnamigen Abschnitt hier). Chronologisch in Ausführungsreihenfolge, älteste zuerst.
-- Neue Migrationen: database/migrations/YYYY-MM-DD_name.sql — nach dem Ausführen auf dev + prod
-- hier unten anhängen und die Einzeldatei löschen.
-- ============================================================================================


-- ── migrate_manager_to_global.sql  (2026-05-20) ───────────────────────────────────────────────────

-- Migration: Manager-Tabellen von Liga-DB nach globaler DB verschieben
-- Einmalig auf dem Server ausführen BEVOR das neue Deployment deployed wird.
--
-- Voraussetzungen:
--   1. global_schema.sql wurde bereits auf dem Server ausgeführt (neue Tabellen existieren)
--   2. Beide DBs laufen auf demselben MySQL-Server mit demselben User
--
-- Ersetze DB_GLOBAL und DB_LEAGUE mit den echten Datenbanknamen (aus .env):
--   DB_GLOBAL  = DB_NAME        = usr_ud16_151_1
--   DB_LEAGUE  = DB_NAME_LEAGUE = usr_ud16_151_4
--
-- HINWEIS: Spalten werden explizit benannt um Reihenfolge-Unterschiede und
--          Charset-Konvertierungen (alte DB: utf16, neue DB: utf8mb3) sicher zu handhaben.

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Manager
INSERT IGNORE INTO usr_ud16_151_1.manager
    (id, manager_name, first_name, alias, password, status, email, date_of_birth, last_activity)
SELECT
    id, manager_name, first_name, alias, password, status, email, date_of_birth, last_activity
FROM usr_ud16_151_4.manager;

-- 2. Rollen
INSERT IGNORE INTO usr_ud16_151_1.manager_role
    (id, manager_id, role)
SELECT
    id, manager_id, role
FROM usr_ud16_151_4.manager_role;

-- 3. Passwort-Reset-Tokens
INSERT IGNORE INTO usr_ud16_151_1.password_reset_token
    (id, manager_id, token_hash, expires_at, used, created_at)
SELECT
    id, manager_id, token_hash, expires_at, used, created_at
FROM usr_ud16_151_4.password_reset_token;

-- 4. Notifications
INSERT IGNORE INTO usr_ud16_151_1.notification
    (id, sender_id, receiver_id, title, message, created_at, read_at)
SELECT
    id, sender_id, receiver_id, title, message, created_at, read_at
FROM usr_ud16_151_4.notification;

-- 5. Notification-Preferences
INSERT IGNORE INTO usr_ud16_151_1.notification_preference
    (manager_id, event_type, enabled)
SELECT
    manager_id, event_type, enabled
FROM usr_ud16_151_4.notification_preference;

-- 6. Manager-Achievements
INSERT IGNORE INTO usr_ud16_151_1.manager_achievement
    (id, manager_id, achievement_id, earned_at, reason, seen_at, level)
SELECT
    id, manager_id, achievement_id, earned_at, reason, seen_at, level
FROM usr_ud16_151_4.manager_achievement;

-- 7. Maintainer-Contributions
INSERT IGNORE INTO usr_ud16_151_1.maintainer_contribution
    (id, manager_id, player_rating_id, contribution_type, created_at)
SELECT
    id, manager_id, player_rating_id, contribution_type, created_at
FROM usr_ud16_151_4.maintainer_contribution;

-- 8. Manager-League-Zuordnungen (alle bestehenden Manager der Liga zuordnen)
--    Holt die league.id anhand des db_name aus der globalen DB
INSERT IGNORE INTO usr_ud16_151_1.manager_league (manager_id, league_id)
SELECT m.id, l.id
FROM usr_ud16_151_1.manager m
CROSS JOIN usr_ud16_151_1.league l
WHERE l.db_name = 'usr_ud16_151_4';

SET FOREIGN_KEY_CHECKS = 1;

-- Verifikation: Zählungen prüfen
SELECT 'manager'                    AS tabelle, COUNT(*) AS count FROM usr_ud16_151_1.manager
UNION ALL
SELECT 'manager (liga)',             COUNT(*) FROM usr_ud16_151_4.manager
UNION ALL
SELECT 'manager_role',               COUNT(*) FROM usr_ud16_151_1.manager_role
UNION ALL
SELECT 'manager_role (liga)',        COUNT(*) FROM usr_ud16_151_4.manager_role
UNION ALL
SELECT 'manager_league',             COUNT(*) FROM usr_ud16_151_1.manager_league
UNION ALL
SELECT 'notification',               COUNT(*) FROM usr_ud16_151_1.notification
UNION ALL
SELECT 'notification (liga)',        COUNT(*) FROM usr_ud16_151_4.notification
UNION ALL
SELECT 'notification_preference',    COUNT(*) FROM usr_ud16_151_1.notification_preference
UNION ALL
SELECT 'manager_achievement',        COUNT(*) FROM usr_ud16_151_1.manager_achievement
UNION ALL
SELECT 'manager_achievement (liga)', COUNT(*) FROM usr_ud16_151_4.manager_achievement
UNION ALL
SELECT 'maintainer_contribution',    COUNT(*) FROM usr_ud16_151_1.maintainer_contribution
UNION ALL
SELECT 'maintainer_contribution (liga)', COUNT(*) FROM usr_ud16_151_4.maintainer_contribution;


-- ── migrate_player_offer.sql  (2026-09-21) ────────────────────────────────────────────────────────

-- Migration: Direktangebote zwischen Managern (player_offer)
-- MANUELL auf JEDER Liga-DB ausführen (dev UND prod), z.B. usr_ud16_151_4 — vor dem Deploy der API.
-- Idempotent bis auf das ALTER TABLE (schlägt beim zweiten Lauf mit "Duplicate column" fehl — dann ignorieren).

-- Tabelle: player_offer (Direktangebot eines Managers für einen Spieler, der aktuell im Team eines
-- anderen Managers ist — "Hinterzimmerdeal", siehe /player_offer). Anders als offer wird nicht am
-- Ende einer Transferphase ausgewertet, sondern vom Verkäufer innerhalb einer Phase angenommen/abgelehnt
-- und dann sofort vollzogen. Gültigkeit: bis Ende des Transferfensters expires_window_id (laufende Phase,
-- sonst nächste) — Ablauf wird immer live gegen transferwindow.end_date bewertet (kein Cron), 'expired'
-- wird nur opportunistisch nachgetragen. offer_value ist nach dem Anlegen unveränderlich.
CREATE TABLE IF NOT EXISTS player_offer (
    id                CHAR(36)   NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_id         CHAR(36)   NOT NULL,             -- Referenz auf global_schema.player.id (kein FK, cross-DB)
    buyer_team_id     CHAR(36)   NOT NULL,             -- bietendes Team
    seller_team_id    CHAR(36)   NOT NULL,             -- Team, das den Spieler zum Angebotszeitpunkt hält
    offer_value       INT        NOT NULL,             -- Geldangebot (reserviert das Budget des Bieters, solange pending)
    price_snapshot    INT        NOT NULL,             -- Marktwert (Verkaufsformel) zum Zeitpunkt des Angebots
    status            ENUM('pending', 'accepted', 'declined', 'cancelled', 'expired', 'void') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
                                                       -- void = hinfällig (Spieler anderweitig vergeben/verkauft)
    expires_window_id CHAR(36)   NOT NULL,             -- Referenz auf global_schema.transferwindow.id (kein FK, cross-DB) — Ende dieses Fensters = Ablauf
    settled_window_id CHAR(36)   NULL DEFAULT NULL,    -- Transferfenster, in dem der Deal angenommen wurde (für die Hinterzimmerdeals-Ansicht)
    parent_offer_id   CHAR(36)   NULL DEFAULT NULL,    -- reserviert für Gegenangebote (Phase 2)
    created_at        DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME   NULL DEFAULT NULL,
    responded_at      DATETIME   NULL DEFAULT NULL,
    open_key          CHAR(73)   GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(buyer_team_id, '|', player_id), NULL)) STORED,
    FOREIGN KEY (buyer_team_id) REFERENCES team(id),
    FOREIGN KEY (seller_team_id) REFERENCES team(id),
    FOREIGN KEY (parent_offer_id) REFERENCES player_offer(id),
    UNIQUE KEY uk_player_offer_open (open_key)         -- max. 1 offenes Angebot pro Bieter+Spieler
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabelle: player_offer_player (Spieler als Gegenwert im Angebot — Phase 3, in Phase 1 ungenutzt)
CREATE TABLE IF NOT EXISTS player_offer_player (
    id              CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    player_offer_id CHAR(36) NOT NULL,
    player_id       CHAR(36) NOT NULL,                 -- Referenz auf global_schema.player.id (kein FK, cross-DB) — Spieler des Bieters
    FOREIGN KEY (player_offer_id) REFERENCES player_offer(id) ON DELETE CASCADE,
    UNIQUE KEY uk_player_offer_player (player_offer_id, player_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE player_in_team
    ADD COLUMN player_offer_id CHAR(36) NULL DEFAULT NULL AFTER sell_id,
    ADD CONSTRAINT fk_pit_player_offer FOREIGN KEY (player_offer_id) REFERENCES player_offer(id);


-- ── migrate_player_offer_counter.sql  (2026-09-21) ────────────────────────────────────────────────

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


-- ── migrate_deal_system_toggle.sql  (2026-09-22) ──────────────────────────────────────────────────

-- Migration: Deal-System an/aus pro Liga (league.deal_system_enabled)
-- MANUELL auf der globalen DB ausführen (dev UND prod), vor dem Deploy der API. Default FALSE.

ALTER TABLE league
    ADD COLUMN deal_system_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER powerranking_enabled;


-- ── migrate_noten_guest_visit.sql  (2026-09-22) ───────────────────────────────────────────────────

-- Migration: anonymes Aufruf-Tracking für /noten (noten_guest_visit)
-- MANUELL auf der globalen DB ausführen (dev UND prod), vor dem Deploy der API.

CREATE TABLE IF NOT EXISTS noten_guest_visit (
    id          CHAR(36)    NOT NULL DEFAULT (UUID()) PRIMARY KEY,
    anon_id     CHAR(36)    NULL DEFAULT NULL,
    started_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    device_type VARCHAR(10) NULL DEFAULT NULL,
    os          VARCHAR(20) NULL DEFAULT NULL,
    browser     VARCHAR(20) NULL DEFAULT NULL,
    INDEX idx_noten_guest_visit_lookup (anon_id, ended_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ── migrate_club_colors.sql  (2026-09-25) ─────────────────────────────────────────────────────────

-- Migration: Vereinsfarben (club.primary_color / club.secondary_color)
-- MANUELL auf der globalen DB ausführen (dev UND prod), vor dem Deploy der API.
-- Beide Spalten optional (NULL = keine Farbe gepflegt) — gedacht v.a. für Bundesliga-Clubs,
-- z.B. Rand der Sticker-Karte ("Die Klebrigsten"). Format: Hex #rrggbb.

ALTER TABLE club
    ADD COLUMN primary_color VARCHAR(7) DEFAULT NULL AFTER logo_uploaded,
    ADD COLUMN secondary_color VARCHAR(7) DEFAULT NULL AFTER primary_color;


-- ── migrate_stickers.sql  (2026-09-25) ────────────────────────────────────────────────────────────

-- Migration: "Die Klebrigsten" (Sticker-Album) — Feature-Schalter pro Liga + Album/Packs/Züge
-- MANUELL auf der globalen DB ausführen (dev UND prod), VOR dem Deploy der API.

ALTER TABLE league
    ADD COLUMN sticker_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER deal_system_enabled;

-- Eingefrorenes Album einer Saison (POST /sticker/album/sync, nur ergänzend — nie gelöscht)
CREATE TABLE IF NOT EXISTS sticker (
    id          CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id   CHAR(36)    NOT NULL,
    sticker_key VARCHAR(50) NOT NULL,  -- player_id bzw. '{club_id}-logo' / '{club_id}-stadium' (= ID im Frontend)
    kind        ENUM('player', 'logo', 'stadium') CHARACTER SET utf8mb4 NOT NULL,
    club_id     CHAR(36)    NOT NULL,  -- Verein am Stichtag 1.9.
    player_id   CHAR(36)    NULL,      -- nur kind = 'player'
    position    ENUM('GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD') CHARACTER SET utf8mb4 NULL,
    price       INT         NOT NULL,  -- Gewichtungs-Marktwert beim Einfrieren (bestimmt Seltenheit)
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES season(id),
    FOREIGN KEY (club_id)   REFERENCES club(id),
    FOREIGN KEY (player_id) REFERENCES player(id),
    UNIQUE KEY uk_sticker (season_id, sticker_key)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Vergebene Packs; source_key macht die Vergabe idempotent (je Manager max. 1 Pack pro Ereignis)
CREATE TABLE IF NOT EXISTS sticker_pack (
    id         CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36)     NOT NULL,
    season_id  CHAR(36)     NOT NULL,
    source     ENUM('daily', 'milestone', 'matchday_best', 'admin') CHARACTER SET utf8mb4 NOT NULL,
    source_key VARCHAR(120) NOT NULL,  -- z.B. 'daily:2026-09-25', 'milestone:{team_id}:200', 'matchday_best:{team_id}:{matchday_id}'
    league_id  CHAR(36)     NULL,      -- Liga des Ereignisses (Meilenstein/Spieltagsbester)
    size       TINYINT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at  DATETIME     NULL,      -- NULL = ungeöffnet
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (season_id)  REFERENCES season(id),
    FOREIGN KEY (league_id)  REFERENCES league(id),
    UNIQUE KEY uk_sticker_pack (manager_id, source_key),
    KEY idx_sticker_pack_open (manager_id, opened_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Gezogene Karten (beim Öffnen eines Packs serverseitig gewürfelt); Sammlung = Summe der Züge
CREATE TABLE IF NOT EXISTS sticker_pull (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    pack_id    CHAR(36) NOT NULL,
    manager_id CHAR(36) NOT NULL,
    sticker_id CHAR(36) NOT NULL,
    slot       TINYINT UNSIGNED NOT NULL,  -- Reihenfolge im Pack
    holo       ENUM('silver', 'gold') CHARACTER SET utf8mb4 NULL,  -- NULL = normale Karte
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pack_id)    REFERENCES sticker_pack(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (sticker_id) REFERENCES sticker(id),
    KEY idx_sticker_pull_manager (manager_id, sticker_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ── migrate_sticker_pack_announced.sql  (2026-09-25) ──────────────────────────────────────────────

-- Migration: "Die Klebrigsten" — Ankündigung neuer Packs geräteübergreifend merken (sticker_pack.announced_at)
-- MANUELL auf der globalen DB ausführen (dev UND prod), nach migrate_stickers.sql.

ALTER TABLE sticker_pack
    ADD COLUMN announced_at DATETIME NULL AFTER created_at;  -- NULL = noch nicht groß angekündigt

-- Bereits vorhandene ungeöffnete Packs nicht nachträglich noch einmal groß ankündigen
UPDATE sticker_pack SET announced_at = NOW() WHERE announced_at IS NULL;


-- ── migrate_sticker_shop.sql  (2026-09-26) ────────────────────────────────────────────────────────

-- Migration: "Die Klebrigsten"-Shop — Lukaten gegen Sticker-Packs (sticker_shop_purchase)
-- MANUELL auf JEDER Liga-DB ausführen (dev UND prod). Idempotent.
-- Ohne die Tabelle rechnet die API weiter wie bisher (Shop-Ausgaben = 0).

CREATE TABLE IF NOT EXISTS sticker_shop_purchase (
    id         CHAR(36)    NOT NULL PRIMARY KEY DEFAULT (UUID()),
    manager_id CHAR(36)    NOT NULL,             -- Referenz auf global_schema.manager.id (kein FK, cross-DB)
    season_id  CHAR(36)    NOT NULL,             -- Referenz auf global_schema.season.id (kein FK, cross-DB)
    offer_key  VARCHAR(30) NOT NULL,             -- gekauftes Angebot (Shop-Konfiguration)
    price      INT         NOT NULL,             -- bezahlte Lukaten (Snapshot)
    pack_id    CHAR(36)    NULL DEFAULT NULL,    -- Referenz auf global_schema.sticker_pack.id (kein FK, cross-DB)
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sticker_shop_purchase (season_id, manager_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ── migrate_sticker_trade.sql  (2026-09-26) ───────────────────────────────────────────────────────

-- Migration: "Die Klebrigsten" — Tauschen unter Managern (sticker_trade, sticker_trade_item, sticker_pull.trade_id)
-- MANUELL auf der globalen DB ausführen (dev UND prod). Idempotent bis auf das ALTER TABLE
-- (schlägt beim zweiten Lauf mit "Duplicate column" fehl — dann ignorieren).

-- Tauschangebot: from_manager bietet to_manager eigene Doppelte gegen dessen Doppelte an
CREATE TABLE IF NOT EXISTS sticker_trade (
    id              CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    season_id       CHAR(36) NOT NULL,
    from_manager_id CHAR(36) NOT NULL,   -- hat das Angebot gemacht
    to_manager_id   CHAR(36) NOT NULL,   -- nimmt an / lehnt ab
    status          ENUM('pending', 'accepted', 'declined', 'cancelled', 'void') CHARACTER SET utf8mb4 NOT NULL DEFAULT 'pending',
                                          -- void = hinfällig (ein Sticker ist kein Doppelter mehr)
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at    DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (season_id)       REFERENCES season(id),
    FOREIGN KEY (from_manager_id) REFERENCES manager(id) ON DELETE CASCADE,
    FOREIGN KEY (to_manager_id)   REFERENCES manager(id) ON DELETE CASCADE,
    KEY idx_sticker_trade_to (to_manager_id, status),
    KEY idx_sticker_trade_from (from_manager_id, status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Je Sticker eine Zeile; giver_id = wer ihn abgibt (from_manager_id oder to_manager_id)
CREATE TABLE IF NOT EXISTS sticker_trade_item (
    id         CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    trade_id   CHAR(36) NOT NULL,
    sticker_id CHAR(36) NOT NULL,
    giver_id   CHAR(36) NOT NULL,
    FOREIGN KEY (trade_id)   REFERENCES sticker_trade(id) ON DELETE CASCADE,
    FOREIGN KEY (sticker_id) REFERENCES sticker(id),
    UNIQUE KEY uk_sticker_trade_item (trade_id, sticker_id, giver_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Getauschte Karten wechseln den Besitzer (manager_id), trade_id markiert sie
ALTER TABLE sticker_pull
    ADD COLUMN trade_id CHAR(36) NULL DEFAULT NULL AFTER holo;


-- ── migrate_sticker_shop_pack.sql  (2026-09-26) ───────────────────────────────────────────────────

-- Migration: "Die Klebrigsten"-Shop — gekaufte Packs (sticker_pack.source 'shop' + Vereins-Pack/Neu-Garantie)
-- MANUELL auf der globalen DB ausführen (dev UND prod). Nicht idempotent (ADD COLUMN schlägt beim zweiten Lauf
-- mit "Duplicate column" fehl — dann ignorieren). Gehört zu migrate_sticker_shop.sql (Liga-DBs).

ALTER TABLE sticker_pack
    MODIFY COLUMN source ENUM('daily', 'milestone', 'matchday_best', 'admin', 'shop') CHARACTER SET utf8mb4 NOT NULL,
    ADD COLUMN club_id        CHAR(36)         NULL DEFAULT NULL AFTER league_id,  -- Vereins-Pack: nur Sticker dieses Vereins
    ADD COLUMN guaranteed_new TINYINT UNSIGNED NULL DEFAULT NULL AFTER size;       -- so viele Karten garantiert neu (NULL = Regel je source)


-- ── migrate_sticker_shop_eur.sql  (2026-09-26) ────────────────────────────────────────────────────

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


-- ── migrate_sticker_pack_kind.sql  (2026-09-27) ───────────────────────────────────────────────────

-- Migration: "Die Klebrigsten" — feste Pack-Arten im Shop (normal/big/club/special) + Holo-Garantie
-- MANUELL auf der globalen DB ausführen (dev UND prod). Nicht idempotent (ADD COLUMN schlägt beim zweiten Lauf
-- mit "Duplicate column" fehl — dann ignorieren). Setzt migrate_sticker_shop_pack.sql voraus.
-- Ohne diese Migration: Shop-Käufe schlagen fehl (Packs werden mit pack_kind/holo_min angelegt), alles andere läuft.

ALTER TABLE sticker_pack
    ADD COLUMN pack_kind VARCHAR(20)      NULL DEFAULT NULL AFTER source,          -- Shop: normal | big | club | special (Design + Inhalt); NULL = aus source
    ADD COLUMN holo_min  TINYINT UNSIGNED NULL DEFAULT NULL AFTER guaranteed_new;  -- so viele Karten mindestens Holo (Special-Pack: 1)

-- ── 2026-09-28_manager_country.sql  (2026-09-28) ──────────────────────────────────────────────────

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

-- ── 2026-09-29_sticker_pack_special.sql  (2026-09-29) ──────────────────────────────────────────────

-- Migration: Sonder-Packs "Geburtstag" + "Weihnachten" für "Die Klebrigsten"
-- Ziel: GLOBALE DB, dev + prod. Idempotent (MODIFY setzt die ENUM-Liste nur neu, bestehende Werte bleiben erhalten).
--
-- sticker_pack.source bekommt zwei neue Werte:
--   birthday  — am Geburtstag (manager.date_of_birth), sonst beim nächsten Login derselben Saison; source_key birthday:{Jahr}
--   christmas — wer vom 24. bis 26.12. online ist; source_key christmas:{Jahr}
-- Vergabe in StickerPackTrait::getMyStickerState() (GET /sticker/me), Regeln in stickerConfig().

ALTER TABLE sticker_pack
    MODIFY source ENUM('daily', 'milestone', 'matchday_best', 'admin', 'shop', 'birthday', 'christmas')
        CHARACTER SET utf8mb4 NOT NULL;
