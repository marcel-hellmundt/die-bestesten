<?php

class ManagerController extends _BaseController
{
    public static array $methodRoles = [
        'GET'    => 'manager',
        'PATCH'  => 'manager',
        'DELETE' => 'manager',
        'POST'   => 'manager', // further restricted to admin inside role sub-routes
    ];

    protected function get(): mixed
    {
        if (!$this->id) {
            if (!$this->isAdmin()) { http_response_code(403); return ['status' => false, 'message' => 'Forbidden']; }
            return $this->db->getAllManagers();
        }

        if ($this->id === 'me') {
            $manager = $this->db->getManagerById($GLOBALS['auth_manager_id']);
            if (!$manager) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Manager not found'];
            }
            return $manager;
        }

        if ($this->id === 'leagues') {
            return ['leagues' => $this->db->getManagerLeagues($GLOBALS['auth_manager_id'])];
        }

        if ($this->id === 'birthdays') {
            return $this->db->getTodaysBirthdays();
        }

        if ($this->id && $this->sub === 'roles') {
            if (!$this->isAdmin()) { http_response_code(403); return ['status' => false, 'message' => 'Forbidden']; }
            return ['roles' => $this->db->getManagerRoles($this->id)];
        }

        if ($this->id) {
            $manager = $this->db->getManagerWithTeams($this->id);
            if (!$manager) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Manager not found'];
            }
            return $manager;
        }

        return $this->methodNotAllowed();
    }

    protected function patch(): mixed
    {
        if ($this->id !== 'me') return $this->methodNotAllowed();

        $body            = $this->body();
        $currentPassword = $body['current_password'] ?? null;
        $newPassword     = $body['new_password'] ?? null;
        $email           = array_key_exists('email', $body)      ? $body['email']      : 'NOT_SET';
        $firstName       = array_key_exists('first_name', $body) ? $body['first_name'] : 'NOT_SET';

        // Field-only updates — no password required
        if (!$currentPassword && !$newPassword) {
            if ($email !== 'NOT_SET') {
                $this->db->updateManagerEmail($GLOBALS['auth_manager_id'], $email ?: null);
            }
            if ($firstName !== 'NOT_SET') {
                $this->db->updateManagerFirstName($GLOBALS['auth_manager_id'], $firstName ?: null);
            }
            if ($email !== 'NOT_SET' || $firstName !== 'NOT_SET') {
                return ['status' => true];
            }
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
        if ($this->id && $this->sub === 'roles' && $this->sub_id) {
            if (!$this->isAdmin()) { http_response_code(403); return ['status' => false, 'message' => 'Forbidden']; }
            $this->db->removeManagerRole($this->id, $this->sub_id);
            return ['roles' => $this->db->getManagerRoles($this->id)];
        }

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

    protected function post(): mixed
    {
        if ($this->id && $this->sub === 'roles') {
            if (!$this->isAdmin()) { http_response_code(403); return ['status' => false, 'message' => 'Forbidden']; }
            $role = $this->body()['role'] ?? null;
            $allowed = ['maintainer', 'admin'];
            if (!$role || !in_array($role, $allowed)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Ungültige Rolle. Erlaubt: ' . implode(', ', $allowed)];
            }
            if (!$this->db->getManagerById($this->id)) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Manager not found'];
            }
            $this->db->addManagerRole($this->id, $role);
            return ['roles' => $this->db->getManagerRoles($this->id)];
        }
        return $this->methodNotAllowed();
    }
}
