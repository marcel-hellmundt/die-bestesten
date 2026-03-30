<?php

class ManagerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'PATCH' => 'manager', 'DELETE' => 'manager'];

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
        $email           = array_key_exists('email', $body) ? $body['email'] : 'NOT_SET';

        // Email-only update — no password required
        if ($email !== 'NOT_SET' && !$currentPassword && !$newPassword) {
            $this->db->updateManagerEmail($GLOBALS['auth_manager_id'], $email ?: null);
            return ['status' => true];
        }

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

        if ($email !== 'NOT_SET') {
            $this->db->updateManagerEmail($GLOBALS['auth_manager_id'], $email ?: null);
        }

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

        $this->db->markManagerDeleted($id);

        return ['status' => true];
    }

    protected function post(): mixed { return $this->methodNotAllowed(); }
}
