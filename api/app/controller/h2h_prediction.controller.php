<?php

class H2HPredictionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id === 'mine') {
            return $this->db->getMyH2HPredictions($GLOBALS['auth_manager_id']);
        }

        if ($this->id === 'standings') {
            return $this->db->getH2HPredictionStandings();
        }

        if ($this->id === 'available') {
            return $this->db->getAvailableH2HMatches($GLOBALS['auth_manager_id']);
        }

        if ($this->id === 'budget') {
            return [
                'budget'     => $this->db->getLukatenBalance($GLOBALS['auth_manager_id']),
                'max_payout' => $this->db->getLukatenMaxPayout(),
            ];
        }

        if ($this->id === 'budget_standings') {
            return $this->db->getLukatenStandings();
        }

        return $this->methodNotAllowed();
    }

    protected function post(): mixed
    {
        $body    = $this->body();
        $matchId = $body['match_id'] ?? null;
        $pick    = $body['pick']     ?? null;

        $validPicks = ['home', 'draw', 'away'];
        if (!$matchId || !in_array($pick, $validPicks, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'match_id und pick (home|draw|away) erforderlich'];
        }

        // Die Quote kommt nicht aus der Anfrage: submitH2HPrediction() speichert die serverseitig
        // berechnete (ein mitgeschicktes odds wird ignoriert) und gibt sie in der Response zurück.

        // Einsatz in Lukaten — optional, weiterhin unbelasteter Tipp möglich; Validierung (min 1,
        // max Kontostand, ggf. Gewinn-Obergrenze) übernimmt submitH2HPrediction().
        $stake = isset($body['stake']) && $body['stake'] !== null ? (int) $body['stake'] : null;

        return $this->db->submitH2HPrediction($matchId, $GLOBALS['auth_manager_id'], $pick, $stake);
    }

    protected function patch(): mixed { return $this->methodNotAllowed(); }

    protected function delete(): mixed
    {
        if (!$this->id) {
            http_response_code(400);
            return ['status' => false, 'message' => 'match_id required'];
        }
        return $this->db->deleteH2HPrediction($this->id, $GLOBALS['auth_manager_id']);
    }
}
