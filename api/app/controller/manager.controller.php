<?php

class ManagerController extends _BaseController
{
    public static array $publicMethods = [];

    protected function get(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $managerId = $GLOBALS['auth_manager_id'] ?? null;
        if (!$managerId) {
            http_response_code(401);
            return ['status' => false, 'message' => 'Unauthorized'];
        }

        $manager = $this->db->getManagerById($managerId);
        if (!$manager) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Manager not found'];
        }

        return $manager;
    }

    protected function patch(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $managerId = $GLOBALS['auth_manager_id'] ?? null;
        if (!$managerId) {
            http_response_code(401);
            return ['status' => false, 'message' => 'Unauthorized'];
        }

        $body            = $this->body();
        $currentPassword = $body['current_password'] ?? null;
        $newPassword     = $body['new_password'] ?? null;

        if (!$currentPassword || !$newPassword) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Fehlende Felder'];
        }

        $manager = $this->db->getAuthManagerById($managerId);
        if (!$manager || !password_verify($currentPassword, $manager['password'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Aktuelles Passwort inkorrekt'];
        }

        $this->db->updateManagerPassword($managerId, password_hash($newPassword, PASSWORD_DEFAULT));
        return ['status' => true];
    }

    protected function delete(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $managerId = $GLOBALS['auth_manager_id'] ?? null;
        if (!$managerId) {
            http_response_code(401);
            return ['status' => false, 'message' => 'Unauthorized'];
        }

        $body     = $this->body();
        $password = $body['password'] ?? null;

        if (!$password) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Passwort erforderlich'];
        }

        $manager = $this->db->getAuthManagerById($managerId);
        if (!$manager || !password_verify($password, $manager['password'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Passwort inkorrekt'];
        }

        $this->db->deleteManagerById($managerId);
        return ['status' => true];
    }

    protected function post(): mixed { return $this->methodNotAllowed(); }
}
