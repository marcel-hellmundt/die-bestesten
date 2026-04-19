<?php
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database/base.database.php';

class Guard
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function authorize(?string $controllerClass): array
    {
        $method       = $_SERVER['REQUEST_METHOD'];
        $methodRoles  = $controllerClass ? $controllerClass::$methodRoles : [];
        $requiredRole = $methodRoles[$method] ?? 'guest';

        // Guest = no auth needed
        if ($requiredRole === 'guest') {
            return ['status' => true];
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$header) {
            return ['status' => false, 'code' => 401, 'message' => 'Authorization Token nicht gesendet'];
        }

        $token = substr($header, 7); // remove "Bearer "
        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

            $manager = $this->db->getAuthManagerById($decoded->sub);
            if (!$manager) {
                return ['status' => false, 'code' => 401, 'message' => 'Authorization Token enthält fehlerhafte Manager-ID'];
            }

            if ($manager['status'] !== 'active') {
                return ['status' => false, 'code' => 403, 'message' => 'Account ist nicht aktiv'];
            }

            $GLOBALS['auth_manager_id'] = $manager['id'];
            $GLOBALS['auth_roles']      = $manager['roles']; // array, e.g. ['maintainer', 'admin']

            // 'manager' = any authenticated active manager; additional roles require explicit assignment
            if ($requiredRole !== 'manager' && !in_array($requiredRole, $manager['roles'])) {
                return ['status' => false, 'code' => 403, 'message' => 'Forbidden'];
            }

            return ['status' => true];
        } catch (Exception $e) {
            return ['status' => false, 'code' => 401, 'message' => $e->getMessage()];
        }
    }
}
