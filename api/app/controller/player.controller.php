<?php

class PlayerController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        if ($this->id) {
            $player = $this->db->getPlayerById($this->id);
            if (!$player) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Player not found'];
            }
            return $player;
        }

        return $this->db->getPlayerList($this->params['country_id'] ?? null);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
