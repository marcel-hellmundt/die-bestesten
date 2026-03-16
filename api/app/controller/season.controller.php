<?php

class SeasonController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        if ($this->id === 'active') {
            $season = $this->db->getActiveSeason();
            if (!$season) {
                http_response_code(404);
                return ['status' => false, 'message' => 'No active season found'];
            }
            return $season;
        }

        if ($this->id) {
            $season = $this->db->getSeasonById($this->id);
            if (!$season) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Season not found'];
            }
            return $season;
        }

        return $this->db->getSeasonList();
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
