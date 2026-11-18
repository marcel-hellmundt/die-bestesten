-- Migration: Request-Limit für Login und Passwort-Reset
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Einmal ausführen.
--
-- Rein additiv: eine neue Tabelle — der Code auf main kennt sie nicht und läuft unverändert weiter. Ohne diese
-- Migration gibt es kein Limit, Login und Passwort-Reset funktionieren wie bisher (AuthAttemptTrait).
--
-- auth_attempt: je fehlgeschlagenem Login und je Anfrage eines Reset-Links eine Zeile. Zu viele davon in kurzer Zeit
-- (je Name/E-Mail bzw. je IP) sperren weitere Versuche vorübergehend. Ein erfolgreicher Login löscht die
-- Fehlversuche seines Namens; Zeilen älter als ein Tag räumt der Code beim Schreiben selbst weg.

CREATE TABLE IF NOT EXISTS auth_attempt (
    id         BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(10)  NOT NULL,  -- login (fehlgeschlagener Login) | reset (Reset-Link angefordert)
    identifier VARCHAR(255) NOT NULL,  -- eingegebener Name bzw. E-Mail, klein geschrieben
    ip         VARCHAR(45)  NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_attempt_identifier (kind, identifier, created_at),
    INDEX idx_auth_attempt_ip (kind, ip, created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
