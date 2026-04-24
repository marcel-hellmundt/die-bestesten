<?php

class PlayerInSeasonController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest'];

    protected function get(): mixed
    {
        if ($this->id === 'bundesliga_count') {
            $seasonId = $this->params['season_id'] ?? null;
            return ['count' => $this->db->getBundesligaPlayerCount($seasonId)];
        }

        if ($this->id === 'available_players') {
            $seasonId = $this->params['season_id'] ?? null;
            return $this->db->getAvailablePlayers($seasonId);
        }

        http_response_code(400);
        return ['status' => false, 'message' => 'Unknown sub-resource'];
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
