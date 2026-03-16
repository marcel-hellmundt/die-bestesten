<?php
use \Firebase\JWT\JWT;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database/base.database.php';

class Guard
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function authorize(array $request): array
    {
        // Public endpoints
        if ($request['endpoint'] === 'country' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            return ['status' => true];
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$header) {
            return ['status' => false, 'message' => 'Authorization Token nicht gesendet'];
        }

        $token = substr($header, 7); // remove "Bearer "
        try {
            $decoded = JWT::decode($token, $_ENV['JWT_SECRET'], ['HS256']);

            $manager = $this->db->getAuthManagerById($decoded->sub);
            if (!$manager) {
                return ['status' => false, 'message' => 'Authorization Token enthält fehlerhafte Manager-ID'];
            }

            $GLOBALS['auth_manager_id'] = $manager['manager_id'];
            $GLOBALS['auth_role']       = $manager['role'];

            return ['status' => true];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
