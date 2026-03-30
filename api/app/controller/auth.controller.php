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
}
