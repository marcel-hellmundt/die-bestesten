<?php

class H2HGroupController extends _BaseController
{
    public static array $methodRoles = [
        'GET'    => 'manager',
        'POST'   => 'admin',
        'PATCH'  => 'admin',
        'DELETE' => 'admin',
    ];

    protected function get(): mixed
    {
        $seasonId = $this->params['season_id'] ?? null;
        if (!$seasonId) {
            $season   = $this->db->getActiveSeason();
            $seasonId = $season ? $season['id'] : null;
        }
        if (!$seasonId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id required'];
        }
        return $this->db->getH2HGroups($seasonId);
    }

    protected function post(): mixed
    {
        $body      = $this->body();
        $seasonId  = $body['season_id']  ?? null;
        $name      = trim($body['name']  ?? '');
        $sortIndex = isset($body['sort_index']) ? (int) $body['sort_index'] : 0;

        if (!$seasonId || !$name) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id and name required'];
        }

        $id = $this->generateGUID();
        $this->db->createH2HGroup($id, $seasonId, $name, $sortIndex);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        $body = $this->body();

        $fields = array_intersect_key($body, array_flip(['name', 'sort_index']));
        if (!empty($fields)) $this->db->updateH2HGroup($this->id, $fields);

        if (isset($body['teams']) && is_array($body['teams'])) {
            $this->db->setGroupTeams($this->id, $body['teams']);
        }

        return ['status' => true];
    }

    protected function delete(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        $this->db->deleteH2HGroup($this->id);
        return ['status' => true];
    }
}
