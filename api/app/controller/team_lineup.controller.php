<?php

class TeamLineupController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'PATCH' => 'manager'];

    protected function get(): mixed
    {
        $teamId     = $this->params['team_id']     ?? null;
        $matchdayId = $this->params['matchday_id'] ?? null;

        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id required'];
        }

        $result = $this->db->getTeamLineup($teamId, $matchdayId ?: null);
        if ($result === false) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Team not found'];
        }

        return $result;
    }

    protected function patch(): mixed
    {
        $body       = $this->getBody();
        $teamId     = $body['team_id']     ?? null;
        $matchdayId = $body['matchday_id'] ?? null;
        $players    = $body['players']     ?? null;

        if (!$teamId || !$matchdayId || !is_array($players)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id, matchday_id, players required'];
        }

        $owner = $this->db->getTeamOwner($teamId);
        if ($owner !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        if (!$this->db->isMatchdayOpen($matchdayId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Matchday not open for lineup editing'];
        }

        $this->db->updateTeamLineup($teamId, $matchdayId, $players);
        return ['status' => true];
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
