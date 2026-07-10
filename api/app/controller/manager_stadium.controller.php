<?php

class ManagerStadiumController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        return $this->db->getVisitedStadiumIds($GLOBALS['auth_manager_id']);
    }

    protected function post(): mixed
    {
        $stadiumId = $this->body()['stadium_id'] ?? null;
        if (!$stadiumId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'stadium_id is required'];
        }

        $id = $this->generateGUID();
        $this->db->markStadiumVisited($id, $GLOBALS['auth_manager_id'], $stadiumId);

        http_response_code(201);
        return ['status' => true];
    }

    protected function patch(): mixed { return $this->methodNotAllowed(); }

    protected function delete(): mixed
    {
        if (!$this->id) {
            http_response_code(400);
            return ['status' => false, 'message' => 'stadium id required'];
        }

        $this->db->unmarkStadiumVisited($GLOBALS['auth_manager_id'], $this->id);
        return ['status' => true];
    }
}
