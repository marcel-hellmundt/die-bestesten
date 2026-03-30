<?php
require_once __DIR__ . '/../database/base.database.php';

abstract class _BaseController
{
    // Required role per HTTP method: 'guest' | 'manager' | 'maintainer' | 'admin'
    // guest = no auth needed, manager = any authenticated user, etc.
    public static array $methodRoles = [];

    public Database $db;
    public string $endpoint = '';
    public ?string $id      = null;
    public array $params    = [];

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
        $this->id       = $request['id'] ?? null;
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
