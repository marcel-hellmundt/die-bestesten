<?php

/**
 * Spielhalle: einarmiger Bandit (siehe SlotTrait):
 *   GET  /slot       — Automat, Gewinntabelle, eigene letzte Drehs und Bilanz (Auth)
 *   POST /slot/spin  — {stake: 1|2|3} einmal drehen, Gewinn sofort gutgeschrieben (Auth)
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

        $stake = $this->body()['stake'] ?? null;
        if (!is_int($stake)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'stake erforderlich'];
        }
        $result = $this->db->spinSlot($GLOBALS['auth_manager_id'], $stake);
        if (isset($result['error'])) {
            http_response_code($result['error']);
            return ['status' => false, 'message' => $result['message']];
        }
        return ['status' => true] + $result;
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
