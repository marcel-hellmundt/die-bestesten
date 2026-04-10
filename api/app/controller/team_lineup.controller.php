<?php

class TeamLineupController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

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

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
