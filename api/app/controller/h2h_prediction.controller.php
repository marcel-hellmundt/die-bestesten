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

        // Vom Frontend zum Zeitpunkt der Tippabgabe für genau diesen Pick angezeigte Pseudo-Quote
        // — optional, wird unverändert als Snapshot gespeichert (siehe
        // H2HPredictionTrait::submitH2HPrediction).
        $odds = isset($body['odds']) ? (float) $body['odds'] : null;

        return $this->db->submitH2HPrediction($matchId, $GLOBALS['auth_manager_id'], $pick, $odds);
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
