<?php

class ManagerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'user', 'PATCH' => 'user', 'DELETE' => 'user'];

    protected function get(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $manager = $this->db->getManagerById($GLOBALS['auth_manager_id']);
        if (!$manager) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Manager not found'];
        }

        return $manager;
    }

    protected function patch(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $body            = $this->body();
        $currentPassword = $body['current_password'] ?? null;
        $newPassword     = $body['new_password'] ?? null;

        if (!$currentPassword || !$newPassword) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Fehlende Felder'];
        }

        $manager = $this->db->getAuthManagerById($GLOBALS['auth_manager_id']);
        if (!$manager || !password_verify($currentPassword, $manager['password'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Aktuelles Passwort inkorrekt'];
        }

        $this->db->updateManagerPassword($GLOBALS['auth_manager_id'], password_hash($newPassword, PASSWORD_DEFAULT));
        return ['status' => true];
    }

    protected function delete(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $body     = $this->body();
        $password = $body['password'] ?? null;

        if (!$password) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Passwort erforderlich'];
        }

        $manager = $this->db->getAuthManagerById($GLOBALS['auth_manager_id']);
        if (!$manager || !password_verify($password, $manager['password'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Passwort inkorrekt'];
        }

        $name = $manager['manager_name'];
        $alias = $manager['alias'] ?? null;
        $id   = $manager['id'];

        $subject = 'Konto-Löschung angefragt: ' . $name;
        $body    = "Manager möchte sein Konto löschen:\n\n"
                 . "Name:  $name\n"
                 . ($alias ? "Alias: $alias\n" : '')
                 . "ID:    $id\n";

        mail('mail@marcelkrause.de', $subject, $body, 'From: noreply@die-bestesten.de');

        return ['status' => true];
    }

    protected function post(): mixed { return $this->methodNotAllowed(); }
}
