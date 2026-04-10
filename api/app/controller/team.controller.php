<?php

class TeamController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        if ($this->id === 'mine') {
            $team = $this->db->getMyTeamForActiveSeason($GLOBALS['auth_manager_id']);
            if (!$team) {
                http_response_code(404);
                return ['status' => false, 'message' => 'No team found for active season'];
            }
            return $team;
        }

        $team = $this->db->getTeamById($this->id);
        if (!$team) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Team not found'];
        }

        if (isset($this->params['include_ratings'])) {
            $team['ratings'] = $this->db->getTeamRatings($this->id);
        }

        return $team;
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
