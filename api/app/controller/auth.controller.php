<?php
use \Firebase\JWT\JWT;

class AuthController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'guest', 'GET' => 'guest'];

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

        if ($this->id === 'switch-league') {
            return $this->handleSwitchLeague();
        }

        $body = $this->body();
        $name = $body['name'] ?? null;
        $password = $body['password'] ?? null;

        if (!$name || !$password) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Name oder Passwort fehlen'];
        }

        $manager = $this->db->getAuthManagerByNameOrEmail($name);

        if (!$manager || !password_verify($password, $manager['password'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Name oder Passwort inkorrekt'];
        }

        if ($manager['status'] === 'deleted') {
            http_response_code(403);
            return ['status' => false, 'message' => 'Account wurde gelöscht'];
        }

        if ($manager['status'] === 'blocked') {
            http_response_code(403);
            return ['status' => false, 'message' => 'Account wurde deaktiviert'];
        }

        $leagues       = $this->db->getManagerLeagues($manager['id']);
        $activeLeagues = array_values(array_filter($leagues, fn($l) => $l['status'] === 'active'));
        $leagueId      = count($activeLeagues) === 1 ? $activeLeagues[0]['id'] : null;

        $now = time();
        $payload = [
            'sub'          => $manager['id'],
            'manager_name' => $manager['manager_name'],
            'roles'        => $manager['roles'],
            'status'       => $manager['status'],
            'league_id'    => $leagueId,
            'iat'          => $now,
            'exp'          => $now + (60 * 60 * 24 * 7),
        ];

        $token = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
        return ['token' => $token, 'leagues' => $leagues, 'league_id' => $leagueId];
    }

    private function handleSwitchLeague(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$header) {
            http_response_code(401);
            return ['status' => false, 'message' => 'Authorization Token nicht gesendet'];
        }
        try {
            $decoded = \Firebase\JWT\JWT::decode(
                substr($header, 7),
                new \Firebase\JWT\Key($_ENV['JWT_SECRET'], 'HS256')
            );
        } catch (\Exception $e) {
            http_response_code(401);
            return ['status' => false, 'message' => 'Ungültiger Token'];
        }

        $managerId = $decoded->sub;
        $leagueId  = $this->body()['league_id'] ?? null;

        if (!$leagueId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'league_id fehlt'];
        }

        if (!$this->db->isManagerInLeague($managerId, $leagueId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Kein Zugang zu dieser Liga'];
        }

        $manager = $this->db->getAuthManagerById($managerId);

        $now = time();
        $payload = [
            'sub'          => $manager['id'],
            'manager_name' => $manager['manager_name'],
            'roles'        => $manager['roles'],
            'status'       => $manager['status'],
            'league_id'    => $leagueId,
            'iat'          => $now,
            'exp'          => $now + (60 * 60 * 24 * 7),
        ];

        $token = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
        return ['token' => $token, 'league_id' => $leagueId];
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
