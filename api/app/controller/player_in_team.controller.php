<?php

class PlayerInTeamController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        $playerId = $this->params['player_id'] ?? null;
        if ($playerId) {
            $seasonId = $this->params['season_id'] ?? null;
            if ($seasonId) {
                return $this->db->getTeamHistoryByPlayerId($playerId, $seasonId);
            }
            return $this->db->getTeamByPlayerId($playerId);
        }

        $teamId = $this->params['team_id'] ?? null;
        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id or player_id required'];
        }
        if (!empty($this->params['include_former'])) {
            return [
                'current' => $this->db->getSquadByTeamId($teamId),
                'former'  => $this->db->getFormerSquadByTeamId($teamId),
            ];
        }
        return $this->db->getSquadByTeamId($teamId);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
