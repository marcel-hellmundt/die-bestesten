-- Migration: Vereinsfarben (club.primary_color / club.secondary_color)
-- MANUELL auf der globalen DB ausführen (dev UND prod), vor dem Deploy der API.
-- Beide Spalten optional (NULL = keine Farbe gepflegt) — gedacht v.a. für Bundesliga-Clubs,
-- z.B. Rand der Sticker-Karte ("Die Klebrigsten"). Format: Hex #rrggbb.

ALTER TABLE club
    ADD COLUMN primary_color VARCHAR(7) DEFAULT NULL AFTER logo_uploaded,
    ADD COLUMN secondary_color VARCHAR(7) DEFAULT NULL AFTER primary_color;
