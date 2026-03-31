<?php

class LeagueController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest'];

    protected function get(): mixed
    {
        if ($this->id) {
            $league = $this->db->getLeagueById($this->id);
            if (!$league) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Liga nicht gefunden'];
            }
            return $league;
        }

        return $this->db->getLeagueList();
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
