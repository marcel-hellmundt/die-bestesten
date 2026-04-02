<?php

class TeamController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $team = $this->db->getTeamById($this->id);
        if (!$team) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Team not found'];
        }
        return $team;
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
