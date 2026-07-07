<?php

class TeamController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager'];

    protected function get(): mixed
    {
        if (!$this->id) {
            $seasonId = $this->params['season_id'] ?? null;
            if (!$seasonId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'season_id required'];
            }
            return $this->db->getTeamsBySeason($seasonId);
        }

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
        if ($this->id && $this->sub === 'logo') {
            return $this->sub_id === 'takeover' ? $this->takeoverLogo() : $this->uploadLogo();
        }

        $body           = $this->body();
        $teamName       = trim($body['team_name'] ?? '');
        $colorPrimary   = $body['color'] ?? null;
        $colorSecondary = $body['color_secondary'] ?? null;

        if (!$teamName) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_name required'];
        }

        if ($this->db->teamExistsForManagerActiveSeason($GLOBALS['auth_manager_id'])) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Team already exists for this season'];
        }

        $id = $this->generateGUID();
        $this->db->createTeam($id, $GLOBALS['auth_manager_id'], $teamName, $colorPrimary ?: null, $colorSecondary ?: null);
        $this->db->sendTeamCreatedAdminEmail($id, $GLOBALS['auth_manager_id'], $teamName, $colorPrimary ?: null, $colorSecondary ?: null);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }

    private function uploadLogo(): mixed
    {
        $owned = $this->requireOwnTeam();
        if (isset($owned['error'])) return $owned['error'];
        $team = $owned['team'];

        $result = ImageUpload::store($_FILES['image'] ?? [], "team/{$team['season_id']}/{$this->id}.png", 'png');
        if (!$result['status']) {
            http_response_code($result['code']);
            return $result;
        }

        return ['status' => true];
    }

    private function takeoverLogo(): mixed
    {
        $owned = $this->requireOwnTeam();
        if (isset($owned['error'])) return $owned['error'];
        $team = $owned['team'];

        $previous = $this->db->getPreviousTeam($GLOBALS['auth_manager_id']);
        if (!$previous) {
            http_response_code(404);
            return ['status' => false, 'message' => 'No previous team found'];
        }

        $result = ImageUpload::copy(
            "team/{$previous['season_id']}/{$previous['id']}.png",
            "team/{$team['season_id']}/{$this->id}.png"
        );
        if (!$result['status']) {
            http_response_code($result['code']);
            return $result;
        }

        return ['status' => true];
    }

    /** @return array{team: array}|array{error: array} */
    private function requireOwnTeam(): array
    {
        $team = $this->db->getTeamById($this->id);
        if (!$team) {
            http_response_code(404);
            return ['error' => ['status' => false, 'message' => 'Team not found']];
        }
        if ($team['manager_id'] !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['error' => ['status' => false, 'message' => 'Forbidden']];
        }
        return ['team' => $team];
    }
}
