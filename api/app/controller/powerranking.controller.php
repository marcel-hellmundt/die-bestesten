<?php

class PowerrankingController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager'];

    protected function get(): mixed
    {
        $seasonId = $this->params['season_id'] ?? null;
        if (!$seasonId) {
            $season   = $this->db->getActiveSeason();
            $seasonId = $season ? $season['id'] : null;
        }
        if (!$seasonId) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Keine aktive Saison'];
        }
        return $this->db->getPowerrankingState($seasonId, $GLOBALS['auth_manager_id']);
    }

    protected function post(): mixed
    {
        $body     = $this->body();
        $seasonId = $body['season_id'] ?? null;
        $picks    = $body['picks'] ?? null;

        if (!$seasonId || !is_array($picks) || empty($picks)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id und picks[] erforderlich'];
        }
        foreach ($picks as $p) {
            if (!isset($p['team_id'], $p['position'])) {
                http_response_code(400);
                return ['status' => false, 'message' => 'jeder Pick benötigt team_id und position'];
            }
        }

        return $this->db->replacePowerrankingPicks($seasonId, $GLOBALS['auth_manager_id'], $picks);
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
