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
