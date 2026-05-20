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
