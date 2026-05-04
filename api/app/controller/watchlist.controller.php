<?php

class WatchlistController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        $teamId = $this->params['team_id'] ?? null;
        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }
        return $this->db->getWatchlist($teamId);
    }

    protected function post(): mixed
    {
        $body     = $this->body();
        $teamId   = $body['team_id']   ?? null;
        $playerId = $body['player_id'] ?? null;

        if (!$teamId || !$playerId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id and player_id required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        $id = $this->db->addToWatchlist($teamId, $playerId);
        return ['id' => $id];
    }

    protected function patch(): mixed { return $this->methodNotAllowed(); }

    protected function delete(): mixed
    {
        if (!$this->id) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Watchlist entry id required'];
        }
        $body   = $this->body();
        $teamId = $body['team_id'] ?? null;
        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }
        $this->db->removeFromWatchlist($this->id, $teamId);
        return null;
    }
}
