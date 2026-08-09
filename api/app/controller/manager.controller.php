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

        $this->db->sendAccountDeletionAdminEmail($name, $alias, $id);

        $this->db->markManagerDeleted($id);

        return ['status' => true];
    }

    protected function post(): mixed
    {
        if (!$this->id) {
            return $this->create();
        }

        if ($this->id && $this->sub === 'resend-invite') {
            return $this->resendInvite();
        }

        if ($this->id === 'me' && $this->sub === 'photo') {
            $result = ImageUpload::store($_FILES['image'] ?? [], "manager/{$GLOBALS['auth_manager_id']}.jpg", 'jpeg');
            if (!$result['status']) {
                http_response_code($result['code']);
                return $result;
            }
            return ['status' => true];
        }

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

    private function create(): mixed
    {
        if (!$this->isAdmin()) { http_response_code(403); return ['status' => false, 'message' => 'Forbidden']; }

        $body        = $this->body();
        $managerName = trim($body['manager_name'] ?? '');
        $firstName   = isset($body['first_name']) ? trim($body['first_name']) : null;
        $firstName   = $firstName !== '' ? $firstName : null;
        $email       = trim($body['email'] ?? '');
        $leagueId    = $body['league_id'] ?? null;

        if (!$managerName || !$email || !$leagueId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'manager_name, email und league_id sind erforderlich'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Ungültige E-Mail-Adresse'];
        }

        $league = $this->db->getLeagueById($leagueId);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        if ($this->db->managerNameExists($managerName)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Dieser Anzeigename ist bereits vergeben'];
        }
        if ($this->db->managerEmailExists($email)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Diese E-Mail-Adresse wird bereits verwendet — falls der Manager schon einen Account hat, stattdessen "Zu Liga einladen" nutzen'];
        }

        $id = $this->generateGUID();
        $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $this->db->createInvitedManager($id, $managerName, $firstName, $email, $placeholderHash, $leagueId);

        $link = $this->sendInviteMail($id, $managerName, $email);

        http_response_code(201);
        return ['status' => true, 'id' => $id, 'invite_link' => $link];
    }

    private function resendInvite(): mixed
    {
        if (!$this->isAdmin()) { http_response_code(403); return ['status' => false, 'message' => 'Forbidden']; }

        $manager = $this->db->getManagerById($this->id);
        if (!$manager) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Manager not found'];
        }
        if ($manager['status'] !== 'invited') {
            http_response_code(409);
            return ['status' => false, 'message' => 'Manager ist nicht im Status "invited"'];
        }

        $link = $this->sendInviteMail($manager['id'], $manager['manager_name'], $manager['email']);
        return ['status' => true, 'invite_link' => $link];
    }

    private function sendInviteMail(string $managerId, string $managerName, string $email): string
    {
        $token = $this->db->createPasswordResetToken($managerId, 24 * 7); // 7 Tage gültig
        $link  = ($_ENV['FRONTEND_URL'] ?? 'https://die-bestesten.de') . '/login/accept-invite?token=' . $token;

        $safeName = str_replace(["\r", "\n"], '', $managerName);
        $subject  = 'Einladung — die bestesten';
        $body     = "Hallo $safeName,\n\n"
                  . "du wurdest zu die bestesten eingeladen.\n\n"
                  . "Klicke auf den folgenden Link, um dein Passwort festzulegen (gültig 7 Tage):\n\n"
                  . $link . "\n\n"
                  . "Danach bist du automatisch eingeloggt.\n";
        mail($email, $subject, $body, 'From: noreply@die-bestesten.de');

        return $link;
    }
}
