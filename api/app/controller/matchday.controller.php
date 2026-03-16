<?php

class MatchdayController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        if ($this->id) {
            $matchday = $this->db->getMatchdayById($this->id);
            if (!$matchday) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Matchday not found'];
            }
            return $matchday;
        }

        return $this->db->getMatchdayList($this->params['season_id'] ?? null);
    }

    protected function post(): mixed
    {
        if ($this->id !== 'migrate') return $this->methodNotAllowed();

        if (($GLOBALS['auth_role'] ?? null) !== 'admin') {
            http_response_code(403);
            return ['status' => false, 'message' => 'Forbidden'];
        }

        return $this->db->migrateMatchday();
    }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
