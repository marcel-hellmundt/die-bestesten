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
    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $body = $this->body();
        if (!array_key_exists('completed', $body)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Feld "completed" fehlt'];
        }

        $matchday = $this->db->getMatchdayById($this->id);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Matchday not found'];
        }

        $this->db->updateMatchdayCompleted($this->id, (bool) $body['completed']);
        return ['status' => true];
    }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
