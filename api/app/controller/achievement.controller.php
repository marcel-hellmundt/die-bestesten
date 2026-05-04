<?php

class AchievementController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'admin', 'PATCH' => 'manager'];

    protected function get(): mixed
    {
        if (isset($this->params['all'])) {
            if (!in_array('admin', $GLOBALS['auth_roles'] ?? [])) {
                http_response_code(403);
                return ['error' => 'Forbidden'];
            }
            return $this->db->getAllAchievementsAdmin();
        }
        return $this->db->getManagerAchievements($GLOBALS['auth_manager_id']);
    }

    protected function post(): mixed
    {
        if ($this->id !== 'evaluate') return $this->methodNotAllowed();
        if ($this->sub) {
            $this->db->evaluateAchievementById($this->sub);
        } else {
            $this->db->evaluateAchievements(true);
        }
        return ['status' => true];
    }

    protected function patch(): mixed
    {
        if ($this->id !== 'seen') return $this->methodNotAllowed();
        $this->db->markAchievementsSeen($GLOBALS['auth_manager_id']);
        return null;
    }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
