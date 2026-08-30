<?php
require_once __DIR__ . '/../database/base.database.php';
require_once __DIR__ . '/../util/image_upload.util.php';

abstract class _BaseController
{
    // Required role per HTTP method: 'guest' | 'manager' | 'contributor' | 'maintainer' | 'admin'
    // guest = no auth needed, manager = any authenticated user, etc. Roles are hierarchical
    // (see ROLE_RANK) — a manager only ever holds their single highest role, and it also
    // satisfies every requirement ranked at or below it.
    public static array $methodRoles = [];

    // Higher rank = more privileged; a manager's rank must be >= the required role's rank.
    public const ROLE_RANK = ['manager' => 0, 'contributor' => 1, 'maintainer' => 2, 'admin' => 3];

    public Database $db;
    public string $endpoint  = '';
    public ?string $id       = null;
    public ?string $sub      = null;
    public ?string $sub_id   = null;
    public array $params     = [];

    abstract protected function get(): mixed;
    abstract protected function post(): mixed;
    abstract protected function patch(): mixed;
    abstract protected function delete(): mixed;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function setRequest(array $request): void
    {
        $this->endpoint = $request['endpoint'];
        $this->id       = $request['id']     ?? null;
        $this->sub      = $request['sub']    ?? null;
        $this->sub_id   = $request['sub_id'] ?? null;
        $this->params   = $_GET;
    }

    public function getResponse(): mixed
    {
        return match ($_SERVER['REQUEST_METHOD']) {
            'GET'    => $this->get(),
            'POST'   => $this->post(),
            'PATCH'  => $this->patch(),
            'DELETE' => $this->delete(),
            default  => $this->methodNotAllowed(),
        };
    }

    protected function methodNotAllowed(): array
    {
        http_response_code(405);
        return ['status' => false, 'message' => 'Method Not Allowed'];
    }

    protected function myRoleRank(): int
    {
        $role = ($GLOBALS['auth_roles'] ?? [])[0] ?? 'manager';
        return self::ROLE_RANK[$role] ?? 0;
    }

    protected function isAdmin(): bool
    {
        return $this->myRoleRank() >= self::ROLE_RANK['admin'];
    }

    protected function isMaintainer(): bool
    {
        return $this->myRoleRank() >= self::ROLE_RANK['maintainer'];
    }

    protected function isContributor(): bool
    {
        return $this->myRoleRank() >= self::ROLE_RANK['contributor'];
    }

    protected function ownsTeam(string $teamId): bool
    {
        return $this->db->getTeamOwner($teamId) === ($GLOBALS['auth_manager_id'] ?? null);
    }

    protected function body(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    protected function generateGUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
