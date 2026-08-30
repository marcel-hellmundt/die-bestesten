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

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        // Guest = no auth required, but still decode an optional token (if sent) so
        // auth_manager_id/auth_league_id are populated for guest-accessible endpoints
        // that behave differently for a logged-in manager (e.g. /league/mine resolving
        // the manager's currently switched league instead of the deployment default).
        // Invalid/expired tokens are ignored here rather than rejected.
        if ($requiredRole === 'guest') {
            if ($header) {
                try {
                    $decoded = JWT::decode(substr($header, 7), new Key($_ENV['JWT_SECRET'], 'HS256'));
                    $manager = $this->db->getAuthManagerById($decoded->sub);
                    if ($manager && $manager['status'] === 'active') {
                        $GLOBALS['auth_manager_id'] = $manager['id'];
                        $GLOBALS['auth_roles']      = $manager['roles'];
                        $leagueId = $decoded->league_id ?? null;
                        $GLOBALS['auth_league_id'] = $leagueId;
                        if ($leagueId) {
                            $this->db->switchLeagueConnection($leagueId);
                        }
                    }
                } catch (Exception) {
                    // Anonymous fallback — no error on a guest route.
                }
            }
            return ['status' => true];
        }

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

            // Switch to the league DB from JWT (null = no active league)
            $leagueId = $decoded->league_id ?? null;
            $GLOBALS['auth_league_id'] = $leagueId;
            if ($leagueId) {
                $this->db->switchLeagueConnection($leagueId);
            }

            $this->db->touchLastActivity($manager['id']);
            try {
                $this->db->touchSession($manager['id']);
            } catch (Throwable) {
                // Session-Heartbeat darf einen Request nie zum Scheitern bringen.
            }

            // 'manager' = any authenticated active manager; additional roles require explicit
            // assignment. $requiredRole may also be an array of acceptable roles (OR semantics),
            // e.g. ['contributor', 'maintainer'] — the manager needs at least one of them.
            $allowedRoles = is_array($requiredRole) ? $requiredRole : [$requiredRole];
            if (!in_array('manager', $allowedRoles, true) && !array_intersect($allowedRoles, $manager['roles'])) {
                return ['status' => false, 'code' => 403, 'message' => 'Forbidden'];
            }

            // Rolling window: refresh token if less than 3 days remaining
            if (($decoded->exp - time()) < 60 * 60 * 24 * 3) {
                $now      = time();
                $newToken = JWT::encode([
                    'sub'          => $manager['id'],
                    'manager_name' => $manager['manager_name'],
                    'roles'        => $manager['roles'],
                    'status'       => $manager['status'],
                    'league_id'    => $leagueId,
                    'iat'          => $now,
                    'exp'          => $now + (60 * 60 * 24 * 7),
                ], $_ENV['JWT_SECRET'], 'HS256');
                header('X-New-Token: ' . $newToken);
            }

            return ['status' => true];
        } catch (Exception $e) {
            return ['status' => false, 'code' => 401, 'message' => $e->getMessage()];
        }
    }
}
