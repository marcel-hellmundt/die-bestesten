<?php
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database/base.database.php';

class Guard
{
    private Database $db;

    // Role hierarchy — higher index = more permissions
    private static array $ROLE_LEVELS = [
        'guest'      => 0,
        'user'       => 1,
        'maintainer' => 2,
        'admin'      => 3,
    ];

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

            $GLOBALS['auth_manager_id'] = $manager['id'];
            $GLOBALS['auth_role']       = $manager['role'];

            $userLevel     = self::$ROLE_LEVELS[$manager['role']]  ?? 0;
            $requiredLevel = self::$ROLE_LEVELS[$requiredRole]      ?? 1;

            if ($userLevel < $requiredLevel) {
                return ['status' => false, 'code' => 403, 'message' => 'Forbidden'];
            }

            return ['status' => true];
        } catch (Exception $e) {
            return ['status' => false, 'code' => 401, 'message' => $e->getMessage()];
        }
    }
}
