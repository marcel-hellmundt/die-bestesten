<?php

/**
 * Spielhalle: einarmiger Bandit (siehe SlotTrait):
 *   GET  /slot       — Automat, Gewinntabelle, eigene letzte Drehs und Bilanz (Auth)
 *   POST /slot/spin  — {stake: 1|2|3, test?} einmal drehen, Gewinn sofort gutgeschrieben (Auth; test nur Admin)
 */
class SlotController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id !== null) return $this->methodNotAllowed();
        return $this->db->getSlotState($GLOBALS['auth_manager_id']);
    }

    protected function post(): mixed
    {
        if ($this->id !== 'spin' || $this->sub !== null) return $this->methodNotAllowed();

        $body  = $this->body();
        $stake = $body['stake'] ?? null;
        if (!is_int($stake)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'stake erforderlich'];
        }
        // Test-Dreh (nur Admin): nichts gebucht, nichts gespeichert
        $test = ($body['test'] ?? false) === true;
        if ($test && !$this->isAdmin()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Test-Drehs nur für Admins'];
        }
        $result = $this->db->spinSlot($GLOBALS['auth_manager_id'], $stake, $test);
        if (isset($result['error'])) {
            http_response_code($result['error']);
            return ['status' => false, 'message' => $result['message']];
        }
        return ['status' => true] + $result;
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
