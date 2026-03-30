<?php

class PlayerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id) {
            $player = $this->db->getPlayerDetail($this->id, $this->params['season_id'] ?? null);
            if (!$player) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Player not found'];
            }
            return $player;
        }

        if (isset($this->params['club_id'])) {
            return $this->db->getPlayersInClub($this->params['club_id'], $this->params['season_id'] ?? null);
        }

        return $this->db->getPlayerList($this->params['country_id'] ?? null, $this->params['season_id'] ?? null);
    }

    protected function post(): mixed
    {
        if ($this->id !== 'migrate') return $this->methodNotAllowed();
        return $this->db->migratePlayer();
    }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
