<?php

/**
 * Lukaten-Konto (neuer Modus, siehe LukatenAccountTrait):
 *   GET    /lukaten          — eigener Stand: Modus der aktiven Saison, Vorschau, Kontostand (Auth)
 *   GET    /lukaten/account  — das Konto im Einzelnen: Summen, Einträge, Buchungen, Regeln (Auth)
 *   DELETE /lukaten/preview  — eigenes Vorschau-Konto leeren (Admin, nur wo die Vorschau erlaubt ist)
 */
class LukatenController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'DELETE' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id === null) {
            return $this->db->getLukatenState($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'account' && $this->sub === null) {
            return $this->db->getLukatenAccount($GLOBALS['auth_manager_id']);
        }
        return $this->methodNotAllowed();
    }

    protected function delete(): mixed
    {
        if ($this->id !== 'preview' || $this->sub !== null) return $this->methodNotAllowed();

        if (!$this->db->lukatenPreviewAvailable()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Die Lukaten-Vorschau ist auf dieser Umgebung nicht verfügbar'];
        }
        try {
            $deleted = $this->db->resetLukatenPreview($GLOBALS['auth_manager_id']);
        } catch (\Throwable $e) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Migration 2026-10-07_lukaten_account.sql fehlt'];
        }
        return ['status' => true, 'deleted' => $deleted];
    }

    protected function post(): mixed  { return $this->methodNotAllowed(); }
    protected function patch(): mixed { return $this->methodNotAllowed(); }
}
