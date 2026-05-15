<?php

class TeamController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager'];

    protected function get(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        if ($this->id === 'check-name') {
            $name = trim($this->params['name'] ?? '');
            if (strlen($name) < 3) {
                http_response_code(400);
                return ['status' => false, 'message' => 'name must be at least 3 characters'];
            }
            return ['available' => !$this->db->isTeamNameTaken($name)];
        }

        if ($this->id === 'previous') {
            $team = $this->db->getPreviousTeam($GLOBALS['auth_manager_id']);
            if (!$team) {
                http_response_code(404);
                return ['status' => false, 'message' => 'No previous team found'];
            }
            return $team;
        }

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

    protected function post(): mixed
    {
        $body     = $this->body();
        $teamName = trim($body['team_name'] ?? '');
        $color    = $body['color'] ?? null;

        if (!$teamName) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_name required'];
        }
        if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'color must be #rrggbb'];
        }

        if ($this->db->teamExistsForManagerActiveSeason($GLOBALS['auth_manager_id'])) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Team already exists for this season'];
        }

        $id = $this->generateGUID();
        $this->db->createTeam($id, $GLOBALS['auth_manager_id'], $teamName, $color ?: null);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
