-- Migration: Deal-System an/aus pro Liga (league.deal_system_enabled)
-- MANUELL auf der globalen DB ausführen (dev UND prod), vor dem Deploy der API. Default FALSE.

ALTER TABLE league
    ADD COLUMN deal_system_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER powerranking_enabled;
