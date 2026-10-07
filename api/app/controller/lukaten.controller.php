<?php

/**
 * Lukaten-Konto (siehe LukatenAccountTrait):
 *   GET  /lukaten          — eigener Kontostand (Auth)
 *   GET  /lukaten/account  — das Konto im Einzelnen: Summen, offene Einträge, Buchungen, Regeln (Auth)
 *   GET  /lukaten/overview — alle Konten mit Summen je Herkunft (Admin)
 *   POST /lukaten/buy_eur  — Lukaten gegen Euro kaufen, Zahlung per PayPal.me (Auth)
 */
class LukatenController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id === null) {
            return $this->db->getLukatenState($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'account' && $this->sub === null) {
            return $this->db->getLukatenAccount($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'overview' && $this->sub === null) {
            if (!$this->isAdmin()) {
                http_response_code(403);
                return ['status' => false, 'message' => 'Keine Berechtigung'];
            }
            return $this->db->getLukatenOverview();
        }
        return $this->methodNotAllowed();
    }

    protected function post(): mixed
    {
        if ($this->id !== 'buy_eur' || $this->sub !== null) return $this->methodNotAllowed();

        $offerKey = $this->body()['offer_key'] ?? null;
        if (!is_string($offerKey)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'offer_key erforderlich'];
        }
        $result = $this->db->buyLukatenEur($GLOBALS['auth_manager_id'], $offerKey);
        if (isset($result['error'])) {
            http_response_code($result['error']);
            return ['status' => false, 'message' => $result['message']];
        }
        return ['status' => true] + $result;
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
