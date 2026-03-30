<?php
use \Firebase\JWT\JWT;

class AuthController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'guest'];

    protected function get(): mixed
    {
        return $this->methodNotAllowed();
    }
    protected function patch(): mixed
    {
        return $this->methodNotAllowed();
    }
    protected function delete(): mixed
    {
        return $this->methodNotAllowed();
    }

    protected function post(): mixed
    {
        if ($this->id === 'password-reset-request') {
            return $this->handleResetRequest();
        }

        if ($this->id === 'password-reset') {
            return $this->handleReset();
        }

        $body = $this->body();
        $name = $body['name'] ?? null;
        $password = $body['password'] ?? null;

        if (!$name || !$password) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Name oder Passwort fehlen'];
        }

        $manager = $this->db->getAuthManagerByName($name);

        if (!$manager || !password_verify($password, $manager['password'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Name oder Passwort inkorrekt'];
        }

        if ($manager['deleted']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Account wurde gelöscht'];
        }

        if ($manager['status'] === 'blocked') {
            http_response_code(403);
            return ['status' => false, 'message' => 'Account wurde deaktiviert'];
        }

        $now = time();
        $payload = [
            'sub' => $manager['id'],
            'manager_name' => $manager['manager_name'],
            'role' => $manager['role'],
            'status' => $manager['status'],
            'iat' => $now,
            'exp' => $now + (60 * 60 * 24 * 7),
        ];

        $token = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
        return ['token' => $token];
    }

    private function handleResetRequest(): array
    {
        $body  = $this->body();
        $email = trim($body['email'] ?? '');

        if (!$email) {
            http_response_code(400);
            return ['status' => false, 'message' => 'E-Mail erforderlich'];
        }

        // Always return success to not leak whether the email exists
        $manager = $this->db->getManagerByEmail($email);
        if ($manager) {
            $token   = $this->db->createPasswordResetToken($manager['id']);
            $name    = $manager['manager_name'];
            $link    = 'https://claude.die-bestesten.de/login/reset-password?token=' . $token;
            $subject = 'Passwort zurücksetzen — die bestesten';
            $body    = "Hallo $name,\n\n"
                     . "du hast eine Passwort-Zurücksetzen-Anfrage gestellt.\n\n"
                     . "Klicke auf den folgenden Link um ein neues Passwort zu setzen (gültig 1 Stunde):\n\n"
                     . $link . "\n\n"
                     . "Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.\n";
            mail($email, $subject, $body, 'From: noreply@die-bestesten.de');
        }

        return ['status' => true];
    }

    private function handleReset(): array
    {
        $body        = $this->body();
        $token       = $body['token'] ?? null;
        $newPassword = $body['new_password'] ?? null;

        if (!$token || !$newPassword) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Token und neues Passwort erforderlich'];
        }

        if (strlen($newPassword) < 8) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Passwort muss mindestens 8 Zeichen lang sein'];
        }

        $success = $this->db->consumePasswordResetToken($token, password_hash($newPassword, PASSWORD_DEFAULT));

        if (!$success) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Link ungültig oder abgelaufen'];
        }

        return ['status' => true];
    }
}
